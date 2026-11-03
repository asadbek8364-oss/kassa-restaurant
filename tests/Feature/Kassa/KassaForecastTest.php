<?php

namespace Tests\Feature\Kassa;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Services\Kassa\KassaForecastService as F;
use App\Support\Kassa\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Прогнозы: скользящее среднее + тренд, «хватит на N дней», к заказу, товары без продаж. */
class KassaForecastTest extends TestCase
{
    use KassaHelpers;
    use RefreshDatabase;

    private Employee $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Store::current()->update(['name' => 'Тестовый магазин']);
        $this->owner = Employee::create(['name' => 'Владелец', 'role' => 'owner', 'pin' => '5728']);
    }

    /** Продажи по $perDay штук в каждый из последних $days полных дней. */
    private function dailySales($p, int $perDay, int $days = 28): void
    {
        for ($d = 1; $d <= $days; $d++) {
            $this->pastSale($p, $perDay, Clock::today()->subDays($d)->setTime(12, 0));
        }
    }

    public function test_moving_average_without_trend(): void
    {
        $f = F::fromSeries(array_fill(0, 28, 2));
        $this->assertSame(28, $f['days']);
        $this->assertEqualsWithDelta(2.0, $f['avg'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $f['avg_7'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $f['slope'], 1e-9);
        $this->assertSame(0, $f['trend_pct']);
        $this->assertEqualsWithDelta(2.0, $f['rate'], 1e-9);

        $empty = F::fromSeries([]);
        $this->assertSame(0, $empty['days']);
        $this->assertSame(0.0, $empty['rate']);
        $this->assertNull($empty['trend_pct']);
    }

    public function test_trend_extends_the_least_squares_line_to_the_middle_of_the_horizon(): void
    {
        // y = 10 + 0,1·t: среднее 11,35, наклон 0,1 → +0,1 × (28 + 14) / 2 = +2,1
        $f = F::fromSeries(array_map(fn ($t) => 10 + 0.1 * $t, range(0, 27)));
        $this->assertEqualsWithDelta(11.35, $f['avg'], 1e-9);
        $this->assertEqualsWithDelta(0.1, $f['slope'], 1e-9);
        $this->assertEqualsWithDelta(13.45, $f['rate'], 1e-9);
        $this->assertSame(6, $f['trend_pct']); // 0,1 × 7 / 11,35 = 6 % в неделю

        // спад: 4 − 0,1·t → поправка −2,1 ограничена половиной среднего (2,65 / 2)
        $down = F::fromSeries(array_map(fn ($t) => 4 - 0.1 * $t, range(0, 27)));
        $this->assertEqualsWithDelta(2.65 / 2, $down['rate'], 1e-9);

        // резкий рост: y = t → поправка 21 ограничена +50 % от среднего 13,5
        $up = F::fromSeries(range(0, 27));
        $this->assertEqualsWithDelta(13.5 * 1.5, $up['rate'], 1e-9);
        $this->assertSame(52, $up['trend_pct']);
    }

    public function test_short_history_uses_plain_average_without_trend(): void
    {
        $f = F::fromSeries([1, 3, 2, 4, 5]);
        $this->assertSame(5, $f['days']);
        $this->assertEqualsWithDelta(3.0, $f['rate'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $f['avg_7'], 1e-9);
        $this->assertNull($f['trend_pct']);
    }

    public function test_running_out_products_with_days_left_and_order_quantity(): void
    {
        $p = $this->oldProduct(40, ['name' => 'Стекло', 'stock' => 6, 'min_stock' => 2, 'buy_price' => 8000]);
        $q = $this->oldProduct(40, ['name' => 'Кабель', 'stock' => 0, 'min_stock' => 2]);
        $r = $this->oldProduct(40, ['name' => 'Чехол', 'stock' => 100]);
        $n = $this->oldProduct(3, ['name' => 'Новинка', 'stock' => 3, 'min_stock' => 0]);
        $this->dailySales($p, 2);
        $this->dailySales($q, 1);
        $this->dailySales($r, 1);
        $this->dailySales($n, 2, 3);
        // сегодняшний неполный день в прогноз не входит
        $this->pastSale($p, 9, Clock::today());

        $this->loginAs($this->owner, '5728');
        $res = $this->getJson('/api/kassa/forecasts?threshold=7')->assertOk()
            ->assertJsonPath('history_days', 28)
            ->assertJsonPath('horizon_days', 14);
        $rows = collect($res->json('running_out'))->keyBy('name');

        $this->assertSame(['Кабель', 'Новинка', 'Стекло'], $rows->keys()->all()); // по сроку: 0, 1, 3 дня
        $this->assertSame(2.0, (float) $rows['Стекло']['per_day']);
        $this->assertSame(3, $rows['Стекло']['days_left']);
        $this->assertSame(Clock::today()->addDays(3)->toDateString(), $rows['Стекло']['runs_out_on']);
        $this->assertSame(24, $rows['Стекло']['order_qty']);            // 2 × 14 + 2 − 6
        $this->assertSame(24 * 8000, $rows['Стекло']['order_sum']);
        $this->assertSame(0, $rows['Кабель']['days_left']);
        $this->assertSame(16, $rows['Кабель']['order_qty']);            // 1 × 14 + 2 − 0
        $this->assertSame(3, $rows['Новинка']['history_days']);         // история — со дня создания
        $this->assertNull($rows['Новинка']['trend_pct']);
        $this->assertSame(1, $rows['Новинка']['days_left']);
        $res->assertJsonPath('totals.running_out', 3)
            ->assertJsonPath('totals.order_units', 24 + 16 + 25);       // новинка: 2 × 14 + 0 − 3

        $short = $this->getJson('/api/kassa/forecasts?threshold=3')->assertOk();
        $this->assertSame(['Кабель', 'Новинка'], array_column($short->json('running_out'), 'name'));

        $this->getJson('/api/kassa/forecasts?threshold=5')->assertUnprocessable()->assertJsonValidationErrors('threshold');
    }

    public function test_dead_stock_and_frozen_purchase_sum(): void
    {
        $never = $this->oldProduct(40, ['name' => 'Не продавался', 'stock' => 5, 'buy_price' => 12000]);
        $long = $this->oldProduct(80, ['name' => 'Давно продавался', 'stock' => 2, 'buy_price' => 30000]);
        $this->pastSale($long, 1, Clock::today()->subDays(35)->setTime(15, 0));
        $fresh = $this->oldProduct(5, ['name' => 'Новый', 'stock' => 9]);
        $empty = $this->oldProduct(40, ['name' => 'Закончился', 'stock' => 0]);
        $selling = $this->oldProduct(40, ['name' => 'Продаётся', 'stock' => 4]);
        $this->pastSale($selling, 1, Clock::today()->subDays(10)->setTime(15, 0));
        $other = Store::create(['name' => 'Другой магазин']);
        $this->oldProduct(90, ['name' => 'Чужой залежавшийся', 'stock' => 50], $other);

        $this->loginAs($this->owner, '5728', Store::query()->orderBy('id')->first());
        $res = $this->getJson('/api/kassa/forecasts')->assertOk()->assertJsonPath('dead_days', 30);
        $dead = collect($res->json('dead'))->keyBy('name');
        $this->assertSame(['Давно продавался', 'Не продавался'], $dead->keys()->all()); // по замороженной сумме
        $this->assertSame(40, $dead['Не продавался']['idle_days']);
        $this->assertNull($dead['Не продавался']['last_sale_at']);
        $this->assertSame(60000, $dead['Не продавался']['frozen']);
        $this->assertSame(35, $dead['Давно продавался']['idle_days']);
        $this->assertNotNull($dead['Давно продавался']['last_sale_at']);
        $res->assertJsonPath('totals.dead', 2)
            ->assertJsonPath('totals.dead_units', 7)
            ->assertJsonPath('totals.dead_frozen', 60000 + 60000);

        $this->getJson('/api/kassa/forecasts?dead_days=60')->assertOk()
            ->assertJsonPath('totals.dead', 0);
        $this->assertFalse(in_array($fresh->id, array_column($res->json('dead'), 'product_id'), true));
        $this->assertFalse(in_array($empty->id, array_column($res->json('dead'), 'product_id'), true));
    }

    public function test_dashboard_widget_and_product_card_show_the_forecast(): void
    {
        $p = $this->oldProduct(40, ['name' => 'Стекло', 'stock' => 6, 'min_stock' => 2]);
        $this->dailySales($p, 2);
        $this->oldProduct(40, ['name' => 'Залежался', 'stock' => 3, 'buy_price' => 20000]);

        $this->loginAs($this->owner, '5728');
        $this->getJson('/api/kassa/dashboard')->assertOk()
            ->assertJsonPath('forecast.threshold', 7)
            ->assertJsonPath('forecast.running_out_count', 1)
            ->assertJsonPath('forecast.running_out.0.name', 'Стекло')
            ->assertJsonPath('forecast.running_out.0.days_left', 3)
            ->assertJsonPath('forecast.dead_count', 1)
            ->assertJsonPath('forecast.dead_frozen', 60000);

        $this->getJson("/api/kassa/products/{$p->id}")->assertOk()
            ->assertJsonPath('forecast.history_days', 28)
            ->assertJsonPath('forecast.trend_pct', 0)
            ->assertJsonPath('forecast.days_left', 3)
            ->assertJsonPath('forecast.forecast_qty', 28)
            ->assertJsonPath('forecast.order_qty', 24);
    }
}
