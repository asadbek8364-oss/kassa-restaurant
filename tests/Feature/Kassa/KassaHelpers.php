<?php

namespace Tests\Feature\Kassa;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Product;
use App\Models\Kassa\Sale;
use App\Models\Kassa\SaleItem;
use App\Models\Kassa\Store;
use App\Support\Kassa\Clock;
use App\Support\Kassa\Ean13;
use App\Support\Kassa\StoreContext;
use Carbon\CarbonImmutable;

/** Общие помощники тестов «Кассы и склада»: товары, вход, магазины, продажи задним числом. */
trait KassaHelpers
{
    private static int $kassaSeq = 0;

    private static int $kassaSaleNo = 5000;

    /** Выполнить код в контексте магазина (запросы в тесте делят один контейнер). */
    private function inStore(Store $store, callable $fn): mixed
    {
        return app(StoreContext::class)->run($store, fn () => $fn());
    }

    private function makeProduct(array $attrs = [], ?Store $store = null): Product
    {
        $n = ++self::$kassaSeq;
        $d12 = '4790000'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
        $create = fn () => Product::create($attrs + [
            'name' => 'Товар '.$n,
            'category' => 'cables',
            'barcode' => $d12.Ean13::checkDigit($d12),
            'buy_price' => 10000,
            'sell_price' => 25000,
            'stock' => 10,
            'min_stock' => 2,
        ]);

        return $store ? $this->inStore($store, $create) : $create();
    }

    /** Товар, заведённый $days дней назад (прогнозы считают историю со дня создания). */
    private function oldProduct(int $days, array $attrs = [], ?Store $store = null): Product
    {
        $p = $this->makeProduct($attrs, $store);
        $p->created_at = Clock::today()->subDays($days)->setTime(9, 0)->setTimezone(config('app.timezone'));
        $p->save();

        return $p;
    }

    /** Продажа, записанная напрямую (история для прогноза), — без списания остатка. */
    private function pastSale(Product $p, int $qty, CarbonImmutable $at): Sale
    {
        $store = Store::find($p->store_id);

        return $this->inStore($store, function () use ($p, $qty, $at) {
            $ts = $at->setTimezone(config('app.timezone'));
            $sale = Sale::create([
                'number' => ++self::$kassaSaleNo, 'payment' => 'card', 'total' => $qty * $p->sell_price, 'cost_total' => $qty * $p->buy_price,
                'change_amount' => 0, 'created_at' => $ts, 'updated_at' => $ts,
            ]);
            SaleItem::create(['sale_id' => $sale->id, 'product_id' => $p->id, 'name' => $p->name, 'qty' => $qty, 'price' => $p->sell_price, 'cost' => $p->buy_price]);

            return $sale;
        });
    }

    private function loginAs(Employee $e, string $pin, ?Store $store = null): string
    {
        $token = $this->postJson('/api/kassa/login', ['employee_id' => $e->id, 'pin' => $pin, 'store_id' => $store?->id])->assertOk()->json('token');
        $this->withToken($token);

        return $token;
    }
}
