<?php

namespace Tests\Feature\Kassa;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Product;
use App\Models\Kassa\Sale;
use App\Models\Kassa\SaleItem;
use App\Models\Kassa\Store;
use App\Support\Kassa\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Чеки, пробитые без связи: очередь на устройстве уходит на сервер без дублей, конфликты остатков. */
class KassaOfflineSyncTest extends TestCase
{
    use KassaHelpers;
    use RefreshDatabase;

    private Store $store;

    private Employee $owner;

    private Employee $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::current();
        $this->store->update(['name' => 'Тестовый магазин']);
        $this->owner = Employee::create(['name' => 'Владелец', 'role' => 'owner', 'pin' => '5728']);
        $this->seller = Employee::create(['name' => 'Азиз', 'role' => 'seller', 'pin' => '2846']);
    }

    /** Чек в том виде, в каком его кладёт в очередь телефон. */
    private function offline(array $items, array $extra = []): array
    {
        return $extra + [
            'client_uuid' => (string) Str::uuid(),
            'store_id' => $this->store->id,
            'sold_at' => CarbonImmutable::now()->subMinutes(30)->toIso8601String(),
            'payment' => 'card',
            'client_total' => array_sum(array_map(fn ($i) => $i['qty'] * ($i['price'] ?? 0), $items)),
            'items' => $items,
        ];
    }

    private function line(Product $p, int $qty): array
    {
        return ['product_id' => $p->id, 'qty' => $qty, 'name' => $p->name, 'price' => $p->sell_price];
    }

    private function sync(array ...$sales)
    {
        return $this->postJson('/api/kassa/sales/sync', ['sales' => $sales]);
    }

    public function test_offline_sale_is_saved_with_device_time_and_stock_is_reduced(): void
    {
        $p = $this->makeProduct(['sell_price' => 35000, 'buy_price' => 14000, 'stock' => 10]);
        $this->loginAs($this->seller, '2846');
        $soldAt = CarbonImmutable::now()->subHours(2)->startOfMinute();
        $sale = $this->offline([$this->line($p, 3)], ['payment' => 'cash', 'received' => 110000, 'customer' => ' Тимур ', 'sold_at' => $soldAt->toIso8601String()]);

        $res = $this->sync($sale)->assertOk()
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.0.sale.number', 1001)
            ->assertJsonPath('results.0.sale.total', 105000)
            ->assertJsonPath('results.0.sale.offline', true)
            ->assertJsonPath('results.0.sale.conflict', false)
            ->assertJsonPath('results.0.sale.employee.id', $this->seller->id);
        $this->assertSame(7, collect($res->json('products'))->firstWhere('id', $p->id)['stock']);

        $saved = Sale::firstOrFail();
        $this->assertSame(7, $p->fresh()->stock);
        $this->assertTrue($saved->created_at->equalTo($soldAt));
        $this->assertNotNull($saved->synced_at);
        $this->assertSame('Тимур', $saved->customer);
        $this->assertSame(5000, $saved->change_amount);
        $this->assertSame(42000, $saved->cost_total);
        $this->getJson('/api/kassa/sales/'.$saved->id)->assertOk()
            ->assertJsonPath('sale.offline', true)
            ->assertJsonPath('sale.client_total', 105000)
            ->assertJsonPath('sale.items.0.shortage', 0);
        $this->getJson('/api/kassa/journal')->assertOk()->assertJsonPath('entries.0.offline', true);
    }

    public function test_repeated_sync_never_creates_duplicates(): void
    {
        $p = $this->makeProduct(['stock' => 10]);
        $this->loginAs($this->seller, '2846');
        $sale = $this->offline([$this->line($p, 2)]);

        // тот же чек дважды в одной пачке и ещё раз позже (ответ сервера потерялся)
        $this->sync($sale, $sale)->assertOk()
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.1.status', 'replay');
        $this->sync($sale)->assertOk()
            ->assertJsonPath('results.0.status', 'replay')
            ->assertJsonPath('results.0.sale.number', 1001);

        $this->assertSame(1, Sale::count());
        $this->assertSame(8, $p->fresh()->stock);
    }

    public function test_sale_that_reached_the_server_online_is_not_duplicated_by_the_queue(): void
    {
        $p = $this->makeProduct(['stock' => 5]);
        $this->loginAs($this->seller, '2846');
        $sale = $this->offline([$this->line($p, 1)]);
        // телефон отправил чек, сервер сохранил его, но ответ не дошёл — чек остался в очереди
        $this->postJson('/api/kassa/sales', ['client_uuid' => $sale['client_uuid'], 'items' => [['product_id' => $p->id, 'qty' => 1]], 'payment' => 'card'])->assertCreated();

        $this->sync($sale)->assertOk()->assertJsonPath('results.0.status', 'replay');
        $this->assertSame(1, Sale::count());
        $this->assertSame(4, $p->fresh()->stock);
    }

    public function test_stock_conflict_sale_is_accepted_stock_stops_at_zero_and_shortage_is_recorded(): void
    {
        $p = $this->makeProduct(['name' => 'Стекло', 'sell_price' => 39000, 'stock' => 1]);
        $other = $this->makeProduct(['stock' => 4]);
        $this->loginAs($this->seller, '2846');

        $res = $this->sync($this->offline([$this->line($p, 3), $this->line($other, 1)]))->assertOk()
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.0.sale.conflict', true)
            ->assertJsonPath('results.0.sale.shortage_units', 2)
            ->assertJsonPath('results.0.sale.total', 3 * 39000 + 25000);

        $this->assertSame(0, $p->fresh()->stock);
        $this->assertSame(3, $other->fresh()->stock);
        $item = SaleItem::where('product_id', $p->id)->firstOrFail();
        $this->assertSame(3, $item->qty);
        $this->assertSame(2, $item->shortage);
        $saleId = $res->json('results.0.sale.id');
        $this->getJson("/api/kassa/sales/{$saleId}")->assertOk()
            ->assertJsonPath('sale.conflict', true)
            ->assertJsonPath('sale.items.0.shortage', 2);
        // продажа учтена полностью: деньги и штуки
        $this->getJson('/api/kassa/reports?period=today')->assertOk()
            ->assertJsonPath('summary.units', 4)
            ->assertJsonPath('summary.revenue', 3 * 39000 + 25000);
        $this->getJson('/api/kassa/journal')->assertOk()->assertJsonPath('entries.0.conflict', true);
    }

    public function test_two_devices_selling_the_last_item_offline_both_get_recorded(): void
    {
        $p = $this->makeProduct(['stock' => 1]);
        $tokenA = $this->loginAs($this->seller, '2846');
        $tokenB = $this->postJson('/api/kassa/login', ['employee_id' => $this->owner->id, 'pin' => '5728'])->json('token');

        $this->withToken($tokenA)->postJson('/api/kassa/sales/sync', ['sales' => [$this->offline([$this->line($p, 1)])]])
            ->assertOk()->assertJsonPath('results.0.sale.conflict', false);
        $this->withToken($tokenB)->postJson('/api/kassa/sales/sync', ['sales' => [$this->offline([$this->line($p, 1)])]])
            ->assertOk()->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.0.sale.conflict', true);

        $this->assertSame(0, $p->fresh()->stock);
        $this->assertSame([1002, 1001], Sale::orderByDesc('id')->pluck('number')->all());
    }

    public function test_server_prices_win_and_device_total_is_kept_for_comparison(): void
    {
        $p = $this->makeProduct(['sell_price' => 30000, 'stock' => 5]);
        $sale = $this->offline([$this->line($p, 2)]); // на устройстве цена 30 000
        $p->update(['sell_price' => 32000]);           // пока чек ждал, цену подняли

        $this->loginAs($this->seller, '2846');
        $this->sync($sale)->assertOk()
            ->assertJsonPath('results.0.sale.total', 64000)
            ->assertJsonPath('results.0.sale.client_total', 60000)
            ->assertJsonPath('results.0.sale.conflict', true);
    }

    public function test_product_deleted_while_offline_is_recorded_by_device_name_and_price(): void
    {
        $p = $this->makeProduct(['name' => 'Попсокет', 'sell_price' => 29000, 'stock' => 3]);
        $sale = $this->offline([$this->line($p, 2)]);
        $p->delete();

        $this->loginAs($this->seller, '2846');
        $this->sync($sale)->assertOk()->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.0.sale.total', 58000);
        $item = SaleItem::firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame('Попсокет', $item->name);
        $this->assertSame(2, $item->shortage);
    }

    public function test_device_clock_in_the_future_or_too_old_falls_back_to_server_time(): void
    {
        $p = $this->makeProduct(['stock' => 10]);
        $this->loginAs($this->seller, '2846');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 15:00:00', Clock::tz()));

        $this->sync(
            $this->offline([$this->line($p, 1)], ['sold_at' => '2027-01-01T10:00:00+05:00']),
            $this->offline([$this->line($p, 1)], ['sold_at' => '2026-08-01T10:00:00+05:00']),
            $this->offline([$this->line($p, 1)], ['sold_at' => '2026-11-02T18:30:00+05:00']),
        )->assertOk();

        $times = Sale::orderBy('number')->get()->map(fn ($s) => Clock::local($s->created_at)->format('Y-m-d H:i'))->all();
        $this->assertSame(['2026-11-03 15:00', '2026-11-03 15:00', '2026-11-02 18:30'], $times);
    }

    public function test_invalid_or_foreign_store_sales_are_rejected_one_by_one(): void
    {
        $p = $this->makeProduct(['stock' => 10]);
        $otherStore = Store::create(['name' => 'Другой']);
        $q = $this->makeProduct(['stock' => 4], $otherStore);
        $this->loginAs($this->seller, '2846');

        $res = $this->sync(
            $this->offline([$this->line($p, 1)], ['payment' => 'crypto']),
            $this->offline([$this->line($q, 1)], ['store_id' => $otherStore->id]),
            $this->offline([$this->line($p, 1)]),
        )->assertOk();
        $res->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.1.status', 'rejected')
            ->assertJsonPath('results.1.message', 'Чек пробит в магазине, где этот сотрудник не работает')
            ->assertJsonPath('results.2.status', 'created');
        $this->assertSame(9, $p->fresh()->stock);
        $this->assertSame(4, Product::withoutGlobalScopes()->find($q->id)->stock);

        $this->postJson('/api/kassa/sales/sync', ['sales' => []])->assertUnprocessable();
    }

    public function test_owner_queue_from_another_store_is_saved_in_that_store(): void
    {
        $otherStore = Store::create(['name' => 'Чиланзар']);
        $q = $this->makeProduct(['stock' => 4], $otherStore);
        $this->loginAs($this->owner, '5728', $this->store); // владелец уже переключился обратно

        $this->sync($this->offline([$this->line($q, 1)], ['store_id' => $otherStore->id]))->assertOk()
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.0.store_id', $otherStore->id)
            ->assertJsonPath('products', []); // товары другого магазина в каталог текущего не попадают

        $this->assertSame(3, Product::withoutGlobalScopes()->find($q->id)->stock);
        $this->assertSame($otherStore->id, Sale::withoutGlobalScopes()->firstOrFail()->store_id);
        $this->assertSame(0, Sale::count()); // в текущем магазине чека нет
    }

    public function test_sync_requires_login(): void
    {
        $this->postJson('/api/kassa/sales/sync', ['sales' => [['client_uuid' => (string) Str::uuid()]]])->assertUnauthorized();
        $this->getJson('/api/kassa/ping')->assertUnauthorized();
        $this->loginAs($this->seller, '2846');
        $this->getJson('/api/kassa/ping')->assertOk()->assertJsonPath('store_id', $this->store->id);
    }
}
