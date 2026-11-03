<?php

namespace Tests\Feature\Restaurant;

use App\Models\Kassa\Audit;
use App\Models\Kassa\Employee;
use App\Models\Kassa\Income;
use App\Models\Kassa\Product;
use App\Models\Kassa\Sale;
use App\Models\Kassa\Store;
use App\Models\Kassa\Token;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\SyncLog;
use App\Models\Restaurant\Table;
use App\Services\Kassa\KassaService;
use App\Services\Restaurant\Sync\HttpSyncTransport;
use App\Services\Restaurant\Sync\SyncClient;
use App\Services\Restaurant\Sync\SyncException;
use App\Services\Restaurant\Sync\SyncTransport;
use App\Services\Restaurant\Sync\SyncTransportFactory;
use App\Support\Kassa\Ean13;
use App\Support\Kassa\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Синхронизация локального сервера ресторана с онлайн-сервером.
 *
 * «Онлайн-сервер» в тесте — то же приложение на второй базе (SQLite в памяти, соединение online) в режиме
 * DEPLOY_MODE=online. Запросы локального сервера идут через настоящие маршруты /api/restaurant/sync/*
 * с sync-токеном (middleware, контроллер, SyncServer) — подменён только сетевой транспорт.
 */
class SyncTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantHelpers;

    private ?StoreContext $onlineContext = null;

    private Store $onlineStore;

    private string $syncToken;

    /** Если задано — «сеть» до онлайн-сервера падает с этой ошибкой. */
    public ?string $networkDown = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();

        config(['database.connections.online' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        Artisan::call('migrate', ['--database' => 'online', '--force' => true]);

        // онлайн-сервер: тот же ресторан создан в облачной админке, выпущен sync-токен
        $this->online(function () {
            $this->onlineStore = Store::create(['name' => 'Ресторан (онлайн)']);
            app(StoreContext::class)->set($this->onlineStore);
            $owner = Employee::create(['name' => 'Владелец', 'role' => 'owner', 'pin' => '5728']);
            $this->withToken(Token::issue($owner, $this->onlineStore));
            $this->syncToken = $this->postJson('/api/restaurant/admin/sync/token')->assertCreated()->json('token');
        });

        // локальный сервер: адрес онлайн-сервера и токен
        $this->actAs($this->admin);
        $this->putJson('/api/restaurant/admin/sync', ['url' => 'https://online.test', 'token' => $this->syncToken])->assertOk();

        $test = $this;
        $this->app->instance(SyncTransportFactory::class, new class($test) extends SyncTransportFactory
        {
            public function __construct(private SyncTest $test) {}

            public function make(Settings $settings): SyncTransport
            {
                return new class($this->test, (string) $settings->sync_token) implements SyncTransport
                {
                    public function __construct(private SyncTest $test, private string $token) {}

                    public function ping(): array
                    {
                        return $this->test->callOnline('GET', 'ping', [], $this->token);
                    }

                    public function push(array $payload): array
                    {
                        return $this->test->callOnline('POST', 'push', $payload, $this->token);
                    }

                    public function pull(): array
                    {
                        return $this->test->callOnline('GET', 'pull', [], $this->token);
                    }
                };
            }
        });
    }

    /** Выполнить код «на онлайн-сервере»: другая база, режим online, свой контекст ресторана. */
    public function online(callable $fn): mixed
    {
        $default = DB::getDefaultConnection();
        $mode = config('restaurant.deploy_mode');
        $local = app(StoreContext::class);
        DB::setDefaultConnection('online');
        config(['restaurant.deploy_mode' => 'online']);
        $this->onlineContext ??= new StoreContext;
        if (isset($this->onlineStore)) {
            $this->onlineContext->set($this->onlineStore);
        }
        $this->app->instance(StoreContext::class, $this->onlineContext);
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($default);
            config(['restaurant.deploy_mode' => $mode]);
            $this->app->instance(StoreContext::class, $local);
        }
    }

    /** Запрос локального сервера к онлайн-серверу через настоящие маршруты (JSON туда и обратно). */
    public function callOnline(string $method, string $path, array $body, string $token): array
    {
        if ($this->networkDown !== null) {
            throw new SyncException($this->networkDown);
        }
        $payload = json_decode(json_encode($body), true);

        return $this->online(function () use ($method, $path, $payload, $token) {
            $this->onlineContext->forget();
            $res = $this->json($method, '/api/restaurant/sync/'.$path, $payload, ['Authorization' => 'Bearer '.$token]);
            if ($res->status() >= 400) {
                throw new SyncException('Онлайн-сервер ответил ошибкой '.$res->status().': '.$res->json('message'));
            }

            return $res->json();
        });
    }

    private function sync(): SyncLog
    {
        return app(SyncClient::class)->run($this->store, 'manual');
    }

    /** Оплаченный заказ на столике (через приложения официанта и кухни). */
    private function paidOrder(Table $table, array $lines): array
    {
        $this->actAs($this->waiter);
        $order = $this->openOrder($table, $lines)->assertCreated()->json('order');
        $this->actAs($this->cook);
        $this->postJson("/api/restaurant/kitchen/tickets/{$order['tickets'][0]['id']}/ready")->assertOk();
        $this->actAs($this->waiter);

        return $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/pay", ['payment' => 'cash', 'received' => 100000])->assertOk()->json('order');
    }

    public function test_push_sends_closed_orders_and_sales_once(): void
    {
        $paid = $this->paidOrder($this->t1, [$this->line($this->plov, 2)]);
        $this->actAs($this->waiter);
        $this->openOrder($this->t2, [$this->line($this->tea)])->assertCreated(); // открытый заказ не уходит

        $log = $this->sync();
        $this->assertSame('ok', $log->status, (string) $log->error);
        $this->assertSame(1, $log->sent['orders']);
        $this->assertSame(1, $log->sent['sales']);

        $this->online(function () use ($paid) {
            $order = Order::with('items', 'sale')->sole();
            $this->assertSame($paid['number'], $order->number);
            $this->assertSame('paid', $order->status);
            $this->assertSame(90000, $order->total);
            $this->assertSame('Азиз Рахимов', $order->waiter_name);
            $this->assertSame(['Плов чайханский'], $order->items->pluck('name')->all());
            $this->assertSame(90000, $order->sale->total); // заказ связан с продажей
            $this->assertSame($this->onlineStore->id, $order->store_id);
        });
        $local = Order::where('status', 'paid')->sole();
        $this->assertNotNull($local->synced_at);
        $this->assertNotNull(Sale::sole()->cloud_synced_at);
        $this->assertNull(Order::where('status', 'new')->sole()->synced_at);

        // повторный запуск ничего не отправляет и не создаёт дублей
        $again = $this->sync();
        $this->assertSame('ok', $again->status);
        $this->assertSame(0, $again->sent['orders']);
        $this->online(fn () => $this->assertSame([1, 1], [Order::count(), Sale::count()]));
    }

    public function test_repeated_delivery_is_idempotent_and_online_never_overwrites_orders(): void
    {
        $paid = $this->paidOrder($this->t1, [$this->line($this->plov, 2)]);
        $this->sync();

        // ответ «потерялся»: локальный сервер отправляет тот же заказ ещё раз, к тому же изменённым
        Order::whereKey($paid['id'])->toBase()->update(['synced_at' => null, 'total' => 1]);
        Sale::query()->toBase()->update(['cloud_synced_at' => null]);
        $log = $this->sync();
        $this->assertSame('ok', $log->status);
        $this->assertSame(1, $log->sent['orders']); // принят как уже существующий

        $this->online(function () {
            $this->assertSame(1, Order::count());
            $this->assertSame(90000, Order::sole()->total); // онлайн-копия не перезаписана
            $this->assertSame(1, Sale::count());
            $this->assertSame(['ok', 'ok'], SyncLog::where('direction', 'push')->pluck('status')->all());
        });
        $this->assertNotNull(Order::find($paid['id'])->synced_at);
    }

    public function test_pull_brings_menu_prices_tables_staff_and_settings(): void
    {
        $this->online(function () {
            $soups = Category::create(['name' => 'Супы', 'sort' => 5]);
            Dish::create(['category_id' => $soups->id, 'name' => 'Шурпа', 'price' => 38000, 'description' => 'Баранина']);
            Table::create(['name' => 'Терраса', 'seats' => 6]);
            Employee::create(['name' => 'Новый официант', 'role' => 'waiter', 'pin' => '7391']);
            Settings::current()->update(['cook_minutes' => 22, 'receipt_footer' => 'Рахмат!']);
            Store::current()->update(['name' => 'Навруз', 'address' => 'ул. Навои, 30']);
        });

        $log = $this->sync();
        $this->assertSame('ok', $log->status, (string) $log->error);
        $this->assertSame(['settings' => 1, 'categories' => 1, 'dishes' => 1, 'tables' => 1, 'staff' => 1], $log->received);

        $shurpa = Dish::where('name', 'Шурпа')->sole();
        $this->assertSame(38000, $shurpa->price);
        $this->assertSame('Супы', $shurpa->category->name);
        $this->assertNotNull($shurpa->synced_at);
        $this->assertSame(6, Table::where('name', 'Терраса')->sole()->seats);
        $this->assertSame(22, Settings::current()->cook_minutes);
        $this->assertSame(['Навруз', 'ул. Навои, 30'], [$this->store->fresh()->name, $this->store->fresh()->address]);

        // сотрудник, заведённый в онлайн-админке, входит на локальном сервере своим PIN
        $waiter = Employee::where('name', 'Новый официант')->sole();
        $this->assertSame($this->store->id, $waiter->store_id);
        $this->postJson('/api/restaurant/login', ['employee_id' => $waiter->id, 'pin' => '7391', 'app' => 'waiter'])->assertOk();
    }

    public function test_online_wins_when_the_same_dish_changed_on_both_servers(): void
    {
        $this->sync(); // справочники локального сервера ушли на онлайн-сервер
        $uuid = $this->plov->uuid;
        $this->online(fn () => $this->assertSame(45000, Dish::where('uuid', $uuid)->sole()->price));

        // цену поменяли и в ресторане, и в облаке
        $this->actAs($this->admin);
        $this->putJson("/api/restaurant/admin/dishes/{$this->plov->id}", ['category_id' => $this->hot->id, 'name' => 'Плов чайханский', 'price' => 47000])->assertOk();
        $this->online(fn () => Dish::where('uuid', $uuid)->sole()->update(['price' => 50000, 'name' => 'Плов праздничный']));

        $log = $this->sync();
        $this->assertSame('ok', $log->status);
        $this->assertSame(1, $log->sent['conflicts']);
        $plov = $this->plov->fresh();
        $this->assertSame([50000, 'Плов праздничный'], [$plov->price, $plov->name]);
        $this->assertNotNull($plov->synced_at);
        $this->online(fn () => $this->assertSame(50000, Dish::where('uuid', $uuid)->sole()->price));
    }

    public function test_local_change_goes_up_when_online_did_not_touch_the_record(): void
    {
        $this->sync();
        $this->actAs($this->admin);
        $this->putJson("/api/restaurant/admin/tables/{$this->t2->id}", ['name' => '2', 'seats' => 10])->assertOk();
        $this->postJson("/api/restaurant/admin/dishes/{$this->tea->id}/stop", ['stop' => true])->assertOk();
        $soup = $this->postJson('/api/restaurant/admin/dishes', ['category_id' => $this->hot->id, 'name' => 'Мастава', 'price' => 32000])->json('dish');
        $this->assertTrue(collect($this->getJson('/api/restaurant/admin/menu')->json('dishes'))->firstWhere('name', 'Чай зелёный')['unsynced']);

        $log = $this->sync();
        $this->assertSame('ok', $log->status);
        $this->assertSame(0, $log->sent['conflicts']);
        $this->online(function () {
            $this->assertSame(10, Table::where('uuid', $this->t2->uuid)->sole()->seats);
            $this->assertTrue(Dish::where('uuid', $this->tea->uuid)->sole()->stop);
            $this->assertSame(32000, Dish::where('name', 'Мастава')->sole()->price);
        });
        $this->assertNotNull(Dish::find($soup['id'])->synced_at);
        $this->assertSame(10, $this->t2->fresh()->seats);
    }

    public function test_deletions_on_the_online_server_reach_the_restaurant(): void
    {
        $this->sync();
        $this->online(fn () => Dish::where('uuid', $this->lagman->uuid)->sole()->delete());
        $this->sync();
        $this->assertSoftDeleted('restaurant_dishes', ['id' => $this->lagman->id]);
        $this->actAs($this->waiter);
        $this->assertNotContains('Лагман', array_column($this->getJson('/api/restaurant/waiter/menu')->json('dishes'), 'name'));
    }

    public function test_warehouse_movements_and_stock_are_pushed(): void
    {
        $d12 = '478000000001';
        $product = Product::create(['name' => 'Рис девзира, кг', 'category' => 'accessories', 'barcode' => $d12.Ean13::checkDigit($d12), 'buy_price' => 30000, 'sell_price' => 0, 'stock' => 0, 'min_stock' => 5]);
        $kassa = app(KassaService::class);
        $kassa->receive($this->admin, 'Оптовый рынок', [['product_id' => $product->id, 'qty' => 40, 'cost' => 31000]]);
        $kassa->audit($this->admin, [['product_id' => $product->id, 'actual' => 37]]);

        $log = $this->sync();
        $this->assertSame('ok', $log->status, (string) $log->error);
        $this->assertSame([1, 1], [$log->sent['incomes'], $log->sent['audits']]);
        $this->online(function () use ($product) {
            $online = Product::where('uuid', $product->uuid)->sole();
            $this->assertSame([37, 31000], [$online->stock, $online->buy_price]);
            $income = Income::with('items')->sole();
            $this->assertSame(['Оптовый рынок', 1240000, $online->id], [$income->supplier, $income->total, $income->items[0]->product_id]);
            $this->assertSame([40, 37], [Audit::with('items')->sole()->items[0]->expected, Audit::with('items')->sole()->items[0]->actual]);
        });
    }

    public function test_failed_sync_is_logged_flagged_and_retried(): void
    {
        $this->paidOrder($this->t1, [$this->line($this->tea)]);
        $this->networkDown = 'Нет связи с онлайн-сервером (https://online.test): Could not resolve host';

        $log = $this->sync();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Нет связи', $log->error);
        $this->assertNull(Order::sole()->synced_at);

        // пометка «есть неотправленные данные» на главном экране админки
        $this->actAs($this->admin);
        $status = $this->getJson('/api/restaurant/admin/dashboard')->assertOk()->json('sync');
        $this->assertTrue($status['attention']);
        $this->assertSame(1, $status['pending']['orders']);
        $this->getJson('/api/restaurant/me')->assertJsonPath('sync_attention', true);
        $this->postJson('/api/restaurant/admin/sync/run')->assertUnprocessable()->assertJsonPath('ok', false);

        // связь вернулась — повторный запуск (ежечасный --retry) отправляет всё
        $this->networkDown = null;
        $this->artisan('restaurant:sync', ['--retry' => true])->assertSuccessful();
        $this->assertNotNull(Order::sole()->synced_at);
        $this->assertSame('ok', SyncLog::where('direction', 'sync')->latest('id')->first()->status);
        $this->assertSame('retry', SyncLog::where('direction', 'sync')->latest('id')->first()->trigger);
        $this->getJson('/api/restaurant/admin/dashboard')->assertJsonPath('sync.attention', false);
        // повтор только для неудачных: сейчас запускать нечего
        $this->artisan('restaurant:sync', ['--retry' => true])->assertSuccessful();
        $this->assertSame(1, SyncLog::where('direction', 'sync')->where('trigger', 'retry')->count());
    }

    public function test_sync_api_requires_online_mode_and_a_valid_token(): void
    {
        // локальный сервер приём синхронизации не обслуживает
        $this->getJson('/api/restaurant/sync/ping', ['Authorization' => 'Bearer '.$this->syncToken])->assertNotFound();

        $this->online(function () {
            $this->getJson('/api/restaurant/sync/ping', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
            $this->getJson('/api/restaurant/sync/ping', ['Authorization' => 'Bearer '.$this->syncToken])->assertOk()
                ->assertJsonPath('store.name', 'Ресторан (онлайн)');
            $this->postJson('/api/restaurant/sync/push', ['format' => 99], ['Authorization' => 'Bearer '.$this->syncToken])->assertUnprocessable();
        });
        $this->assertStringContainsString('Ресторан (онлайн)', $this->postJson('/api/restaurant/admin/sync/ping')->assertOk()->json('message'));

        // онлайн-сервер не запускает синхронизацию сам и не принимает заказы
        $this->online(function () {
            $this->artisan('restaurant:sync')->expectsOutputToContain('Это онлайн-сервер')->assertExitCode(0)->run();
            $this->withToken(Token::issue(Employee::where('role', 'owner')->sole(), $this->onlineStore));
            $table = Table::create(['name' => '5', 'seats' => 4]);
            $dish = Dish::create(['category_id' => Category::create(['name' => 'Чай'])->id, 'name' => 'Чай', 'price' => 1000]);
            $this->openOrder($table, [['dish_id' => $dish->id, 'qty' => 1]])
                ->assertStatus(409)->assertJsonPath('message', 'Это онлайн-сервер: заказы принимает и ведёт локальный сервер ресторана');
        });
    }

    public function test_http_transport_sends_the_sync_token_over_https(): void
    {
        Http::fake([
            'online.test/api/restaurant/sync/ping' => Http::response(['ok' => true, 'store' => ['name' => 'Навруз']]),
            'online.test/api/restaurant/sync/push' => Http::response(['message' => 'Неверный sync-токен'], 401),
        ]);
        $transport = new HttpSyncTransport('https://online.test/', 'rs_secret', 10);

        $this->assertSame('Навруз', $transport->ping()['store']['name']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://online.test/api/restaurant/sync/ping' && $r->hasHeader('Authorization', 'Bearer rs_secret'));

        try {
            $transport->push(['format' => 1]);
            $this->fail('Ожидалась ошибка токена');
        } catch (SyncException $e) {
            $this->assertStringContainsString('sync-токен', $e->getMessage());
        }
    }
}
