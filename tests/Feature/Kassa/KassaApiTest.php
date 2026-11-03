<?php

namespace Tests\Feature\Kassa;

use App\Console\Commands\KassaDemoCommand;
use App\Models\Kassa\Employee;
use App\Models\Kassa\Product;
use App\Models\Kassa\Sale;
use App\Models\Kassa\Store;
use App\Rules\KassaPin;
use App\Services\Kassa\KassaDemoService;
use App\Support\Kassa\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KassaApiTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Employee $owner;

    private Employee $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Store::current()->update(['name' => 'Тестовый магазин']);
        $this->owner = Employee::create(['name' => 'Бахтиёр Каримов', 'role' => 'owner', 'pin' => '1111']);
        $this->seller = Employee::create(['name' => 'Азиз Рахимов', 'role' => 'seller', 'pin' => '2222']);
    }

    private function product(array $attrs = []): Product
    {
        $n = ++self::$seq;
        $d12 = '4780000'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $d12[$i] * ($i % 2 ? 3 : 1);
        }

        return Product::create($attrs + [
            'name' => 'Товар '.$n,
            'category' => 'cables',
            'barcode' => $d12.((10 - $sum % 10) % 10),
            'buy_price' => 14000,
            'sell_price' => 35000,
            'stock' => 10,
            'min_stock' => 3,
        ]);
    }

    private function loginAs(Employee $e, string $pin): void
    {
        $token = $this->postJson('/api/kassa/login', ['employee_id' => $e->id, 'pin' => $pin])->assertOk()->json('token');
        $this->withToken($token);
    }

    private function sell(array $items, string $payment = 'card', array $extra = [])
    {
        return $this->postJson('/api/kassa/sales', ['items' => $items, 'payment' => $payment] + $extra);
    }

    public function test_api_requires_login_and_checks_pin(): void
    {
        $this->getJson('/api/kassa/bootstrap')->assertUnauthorized();

        $this->postJson('/api/kassa/login', ['employee_id' => $this->seller->id, 'pin' => '9999'])
            ->assertUnprocessable()->assertJsonPath('errors.pin.0', 'Неверный PIN');

        $this->loginAs($this->seller, '2222');
        $this->getJson('/api/kassa/bootstrap')->assertOk()
            ->assertJsonPath('employee.name', 'Азиз Рахимов')
            ->assertJsonPath('store.name', 'Тестовый магазин');
    }

    public function test_pin_guessing_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/kassa/login', ['employee_id' => $this->owner->id, 'pin' => '000'.$i])->assertUnprocessable();
        }

        $this->postJson('/api/kassa/login', ['employee_id' => $this->owner->id, 'pin' => '1111'])->assertStatus(429);
    }

    public function test_regular_api_traffic_from_the_shop_does_not_block_login(): void
    {
        $this->loginAs($this->seller, '2222');
        for ($i = 0; $i < 40; $i++) {
            $this->getJson('/api/kassa/products')->assertOk();
        }

        $this->postJson('/api/kassa/login', ['employee_id' => $this->owner->id, 'pin' => '1111'])->assertOk();
        $this->getJson('/api/kassa/options')->assertOk();
    }

    public function test_sale_uses_server_prices_counts_change_and_reduces_stock(): void
    {
        $cable = $this->product(['sell_price' => 35000, 'stock' => 10]);
        $glass = $this->product(['sell_price' => 39000, 'buy_price' => 9000, 'stock' => 4]);
        $this->loginAs($this->seller, '2222');

        $res = $this->sell([
            ['product_id' => $cable->id, 'qty' => 2, 'price' => 1],
            ['product_id' => $glass->id, 'qty' => 1],
        ], 'cash', ['received' => 150000, 'customer' => ' Тимур '])->assertCreated();

        $res->assertJsonPath('sale.number', 1001)
            ->assertJsonPath('sale.total', 109000)
            ->assertJsonPath('sale.change', 41000)
            ->assertJsonPath('sale.customer', 'Тимур')
            ->assertJsonPath('sale.employee.id', $this->seller->id);
        $this->assertSame(8, $cable->fresh()->stock);
        $this->assertSame(3, $glass->fresh()->stock);
        $this->assertSame(8, collect($res->json('products'))->firstWhere('id', $cable->id)['stock']);
    }

    public function test_cannot_sell_more_than_in_stock(): void
    {
        $p = $this->product(['stock' => 1]);
        $this->loginAs($this->seller, '2222');

        $this->sell([['product_id' => $p->id, 'qty' => 2]])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame(1, $p->fresh()->stock);
        $this->assertSame(0, Sale::count());
    }

    public function test_cash_less_than_total_is_rejected(): void
    {
        $p = $this->product(['sell_price' => 35000]);
        $this->loginAs($this->seller, '2222');

        $this->sell([['product_id' => $p->id, 'qty' => 1]], 'cash', ['received' => 30000])
            ->assertUnprocessable()->assertJsonValidationErrors('received');
        $this->assertSame(10, $p->fresh()->stock);
    }

    public function test_repeated_sale_with_same_client_uuid_is_saved_once(): void
    {
        $p = $this->product(['stock' => 5]);
        $this->loginAs($this->seller, '2222');
        $uuid = (string) Str::uuid();

        $first = $this->sell([['product_id' => $p->id, 'qty' => 1]], 'qr', ['client_uuid' => $uuid])->assertCreated();
        $this->sell([['product_id' => $p->id, 'qty' => 1]], 'qr', ['client_uuid' => $uuid])
            ->assertOk()->assertJsonPath('replay', true)->assertJsonPath('sale.number', $first->json('sale.number'));

        $this->assertSame(4, $p->fresh()->stock);
        $this->assertSame(1, Sale::count());
    }

    public function test_refund_returns_goods_and_cannot_exceed_bought_quantity(): void
    {
        $p = $this->product(['sell_price' => 35000, 'stock' => 10]);
        $this->loginAs($this->seller, '2222');
        $sale = $this->sell([['product_id' => $p->id, 'qty' => 2]])->json('sale');
        $itemId = $sale['items'][0]['id'];

        $this->postJson('/api/kassa/returns', ['sale_id' => $sale['id'], 'items' => [['sale_item_id' => $itemId, 'qty' => 1]], 'reason' => 'Брак'])
            ->assertCreated()->assertJsonPath('return.total', 35000)->assertJsonPath('return.sale_number', $sale['number']);
        $this->assertSame(9, $p->fresh()->stock);

        $this->postJson('/api/kassa/returns', ['sale_id' => $sale['id'], 'items' => [['sale_item_id' => $itemId, 'qty' => 2]], 'reason' => 'Брак'])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->getJson('/api/kassa/sales/'.$sale['id'])->assertOk()
            ->assertJsonPath('sale.items.0.returned', 1)
            ->assertJsonPath('sale.items.0.returnable', 1)
            ->assertJsonPath('sale.has_refund', true);
    }

    public function test_income_adds_stock_and_updates_buy_price(): void
    {
        $p = $this->product(['buy_price' => 9000, 'stock' => 4]);
        $this->loginAs($this->seller, '2222');

        $this->postJson('/api/kassa/incomes', ['supplier' => 'Hoco Tashkent', 'items' => [['product_id' => $p->id, 'qty' => 30, 'cost' => 8500]]])
            ->assertCreated()->assertJsonPath('income.total', 255000)->assertJsonPath('income.number', 1);

        $p->refresh();
        $this->assertSame(34, $p->stock);
        $this->assertSame(8500, $p->buy_price);
    }

    public function test_audit_sets_stock_to_counted_quantity(): void
    {
        $short = $this->product(['stock' => 33, 'buy_price' => 8000]);
        $extra = $this->product(['stock' => 5, 'buy_price' => 26000]);
        $this->loginAs($this->owner, '1111');

        $this->postJson('/api/kassa/audits', ['counts' => [['product_id' => $short->id, 'actual' => 31], ['product_id' => $extra->id, 'actual' => 6]]])
            ->assertCreated()
            ->assertJsonPath('audit.shortage_qty', 2)
            ->assertJsonPath('audit.shortage_sum', 16000)
            ->assertJsonPath('audit.surplus_qty', 1)
            ->assertJsonPath('audit.surplus_sum', 26000);

        $this->assertSame(31, $short->fresh()->stock);
        $this->assertSame(6, $extra->fresh()->stock);
    }

    public function test_reports_are_calculated_on_the_server(): void
    {
        $cable = $this->product(['name' => 'Кабель', 'buy_price' => 14000, 'sell_price' => 35000, 'stock' => 20]);
        $pods = $this->product(['name' => 'Наушники', 'buy_price' => 90000, 'sell_price' => 159000, 'stock' => 5]);
        $this->loginAs($this->seller, '2222');

        $a = $this->sell([['product_id' => $cable->id, 'qty' => 2]], 'cash')->json('sale');            // 70 000
        $this->sell([['product_id' => $pods->id, 'qty' => 1], ['product_id' => $cable->id, 'qty' => 1]], 'card'); // 194 000
        $this->postJson('/api/kassa/returns', ['sale_id' => $a['id'], 'items' => [['sale_item_id' => $a['items'][0]['id'], 'qty' => 1]], 'reason' => 'Передумал'])->assertCreated();

        $report = $this->getJson('/api/kassa/reports?period=today')->assertOk();
        $report->assertJsonPath('summary.gross', 264000)
            ->assertJsonPath('summary.refunds', 35000)
            ->assertJsonPath('summary.revenue', 229000)
            ->assertJsonPath('summary.checks', 2)
            ->assertJsonPath('summary.units', 3)
            ->assertJsonPath('summary.avg', 132000)
            // себестоимость: 3 кабеля × 14 000 + наушники 90 000 − возврат 14 000 = 118 000
            ->assertJsonPath('summary.profit', 111000);
        $this->assertSame(35000, collect($report->json('by_payment'))->firstWhere('payment', 'cash')['value']);
        $this->assertSame('Наушники', $report->json('top.0.name'));
        $this->assertSame(229000, $report->json('by_seller.0.value'));

        $this->getJson('/api/kassa/dashboard')->assertOk()
            ->assertJsonPath('today.revenue', 229000)
            ->assertJsonPath('recent_sales.0.total', 194000);
    }

    public function test_journal_lists_operations_newest_first_and_searches(): void
    {
        $p = $this->product(['name' => 'Попсокет']);
        $this->loginAs($this->seller, '2222');
        $sale = $this->sell([['product_id' => $p->id, 'qty' => 1]], 'card', ['customer' => 'Нигора'])->json('sale');
        $this->travel(5)->seconds();
        $this->postJson('/api/kassa/incomes', ['items' => [['product_id' => $p->id, 'qty' => 3, 'cost' => 9000]]])->assertCreated();

        $this->getJson('/api/kassa/journal')->assertOk()
            ->assertJsonPath('counts.all', 2)
            ->assertJsonPath('entries.0.type', 'inc')
            ->assertJsonPath('entries.1.type', 'sale');

        $this->getJson('/api/kassa/journal?q=Нигора')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('entries.0.number', $sale['number']);
    }

    public function test_seller_cannot_manage_staff_store_or_delete_products(): void
    {
        $p = $this->product();
        $this->loginAs($this->seller, '2222');

        $this->deleteJson("/api/kassa/products/{$p->id}")->assertForbidden();
        $this->postJson('/api/kassa/employees', ['name' => 'Новый', 'role' => 'seller', 'pin' => '7391'])->assertForbidden();
        $this->putJson('/api/kassa/store', ['name' => 'Чужой'])->assertForbidden();
        $this->postJson('/api/kassa/data/wipe')->assertForbidden();

        $this->loginAs($this->owner, '1111');
        $this->postJson('/api/kassa/employees', ['name' => 'Мадина Алиева', 'role' => 'seller', 'pin' => '5555'])
            ->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->postJson('/api/kassa/employees', ['name' => 'Мадина Алиева', 'role' => 'seller', 'pin' => '7391'])->assertCreated();
        $this->deleteJson("/api/kassa/products/{$p->id}")->assertOk();
    }

    public function test_disabled_employee_loses_access_immediately(): void
    {
        $sellerToken = $this->postJson('/api/kassa/login', ['employee_id' => $this->seller->id, 'pin' => '2222'])->json('token');
        $this->loginAs($this->owner, '1111');
        $this->postJson("/api/kassa/employees/{$this->seller->id}/toggle")->assertOk()->assertJsonPath('employee.active', false);

        $this->withToken($sellerToken)->getJson('/api/kassa/bootstrap')->assertUnauthorized();
    }

    public function test_product_needs_valid_unique_ean13_and_opening_stock_is_an_income(): void
    {
        $existing = $this->product();
        $this->loginAs($this->seller, '2222');
        $base = ['name' => 'Чехол iPhone 16', 'category' => 'cases', 'buy_price' => 40000, 'sell_price' => 95000, 'min_stock' => 3];

        $this->postJson('/api/kassa/products', $base + ['barcode' => '4780000000001'])
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->postJson('/api/kassa/products', $base + ['barcode' => $existing->barcode])
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');

        $res = $this->postJson('/api/kassa/products', $base + ['stock' => 12])->assertCreated();
        $this->assertMatchesRegularExpression('/^200\d{10}$/', $res->json('product.barcode'));
        $res->assertJsonPath('product.stock', 12);

        $this->getJson('/api/kassa/incomes')->assertOk()
            ->assertJsonPath('incomes.0.supplier', 'Начальный остаток')
            ->assertJsonPath('incomes.0.units', 12);
    }

    public function test_setup_creates_owner_once(): void
    {
        Employee::query()->delete();

        $this->getJson('/api/kassa/options')->assertOk()->assertJsonPath('needs_setup', true);
        $token = $this->postJson('/api/kassa/setup', ['store_name' => 'Smart Case', 'owner_name' => 'Ойбек', 'pin' => '5728'])
            ->assertCreated()->assertJsonPath('employee.role', 'owner')->json('token');

        $this->withToken($token)->getJson('/api/kassa/bootstrap')->assertOk()->assertJsonPath('store.name', 'Smart Case');
        $this->postJson('/api/kassa/setup', ['store_name' => 'X', 'owner_name' => 'Y', 'pin' => '9184'])->assertStatus(409);
    }

    public function test_setup_rejects_simple_pins(): void
    {
        Employee::query()->delete();

        foreach (['1111', '1234', '987654', '2580', '12'] as $pin) {
            $this->postJson('/api/kassa/setup', ['store_name' => 'Smart Case', 'owner_name' => 'Ойбек', 'pin' => $pin])
                ->assertUnprocessable()->assertJsonValidationErrors('pin');
        }
        $this->assertSame(0, Employee::count());

        foreach (['0000', '4321', '0123', '1212', '777777'] as $weak) {
            $this->assertTrue(KassaPin::isWeak($weak), $weak);
        }
        foreach (['5728', '1357', '904162', '2468'] as $ok) {
            $this->assertFalse(KassaPin::isWeak($ok), $ok);
        }
    }

    public function test_production_setup_is_closed_without_setup_code(): void
    {
        Employee::query()->delete();
        $this->app['env'] = 'production';

        $this->getJson('/api/kassa/options')->assertOk()
            ->assertJsonPath('setup_code_required', true)
            ->assertJsonPath('setup_ready', false)
            ->assertJsonPath('demo_available', false);
        $this->postJson('/api/kassa/setup', ['store_name' => 'Smart Case', 'owner_name' => 'Ойбек', 'pin' => '5728'])->assertForbidden();
        $this->assertSame(0, Employee::count());
    }

    public function test_production_setup_needs_the_right_setup_code(): void
    {
        Employee::query()->delete();
        $this->app['env'] = 'production';
        config(['kassa.setup_code' => 'k7Q2-9xPa']);
        $data = ['store_name' => 'Smart Case', 'owner_name' => 'Ойбек', 'pin' => '5728'];

        $this->getJson('/api/kassa/options')->assertOk()->assertJsonPath('setup_ready', true);
        $this->postJson('/api/kassa/setup', $data + ['setup_code' => 'guess'])
            ->assertUnprocessable()->assertJsonValidationErrors('setup_code');
        $this->postJson('/api/kassa/setup', $data + ['setup_code' => 'k7Q2-9xPa'])
            ->assertCreated()->assertJsonPath('employee.name', 'Ойбек');
    }

    public function test_demo_is_off_by_default_and_never_available_in_production(): void
    {
        $this->assertFalse(KassaDemoService::allowed());
        $this->assertFalse((new KassaDemoCommand)->isEnabled());
        $this->loginAs($this->owner, '1111');
        $this->postJson('/api/kassa/data/demo')->assertNotFound();
        $this->postJson('/api/kassa/data/wipe')->assertNotFound();
        $this->getJson('/api/kassa/bootstrap')->assertOk()->assertJsonPath('features.demo', false);

        Employee::query()->delete();
        $this->postJson('/api/kassa/setup', ['demo' => true])->assertNotFound();
        $this->assertSame(0, Product::count());

        config(['kassa.demo_enabled' => true]);
        $this->app['env'] = 'production';
        $this->assertFalse(KassaDemoService::allowed());
        $this->assertFalse((new KassaDemoCommand)->isEnabled());
        $this->expectException(\RuntimeException::class);
        app(KassaDemoService::class)->load();
    }

    /** Демо включается только явно и вне production; artisan пересобирается, чтобы увидеть команду. */
    private function enableDemo(): void
    {
        config(['kassa.demo_enabled' => true]);
        $this->app->make(ConsoleKernel::class)->setArtisan(null);
    }

    public function test_demo_sales_are_never_in_the_future_even_right_after_midnight(): void
    {
        $this->enableDemo();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 00:00:20', 'Asia/Tashkent'));
        app(KassaDemoService::class)->load();

        $this->assertSame(0, Sale::where('created_at', '>', now())->count());
        $this->assertSame(8, Sale::where('created_at', '>=', Clock::toDb(Clock::today()))->count());
    }

    public function test_demo_dataset_loads_with_todays_sales(): void
    {
        $this->enableDemo();
        $this->artisan('kassa:demo', ['--force' => true])->assertSuccessful();

        $this->assertSame(50, Product::count());
        $this->assertSame(2, Store::count());
        $owner = Employee::where('role', 'owner')->firstOrFail();
        $this->loginAs($owner, '1111');

        $dashboard = $this->getJson('/api/kassa/dashboard')->assertOk();
        $this->assertSame(8, $dashboard->json('today.checks'));
        $this->assertCount(6, $dashboard->json('low_stock'));
        $this->assertSame(2, $dashboard->json('forecast.dead_count'));
        $this->assertGreaterThan(0, $dashboard->json('forecast.running_out_count'));
        $this->getJson('/api/kassa/reports?period=month')->assertOk()->assertJsonPath('series.unit', 'day');

        $stores = $this->getJson('/api/kassa/stores')->assertOk();
        $this->assertSame(['Mobi Aksessuar', 'Mobi Aksessuar Chilonzor'], array_column($stores->json('stores'), 'name'));
        $this->assertSame(4, $stores->json('stores.1.summary.checks'));
        $this->assertSame(12, $stores->json('total.checks'));
    }
}
