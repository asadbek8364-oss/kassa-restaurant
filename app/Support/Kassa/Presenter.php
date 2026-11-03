<?php

namespace App\Support\Kassa;

use App\Models\Kassa\Audit;
use App\Models\Kassa\Employee;
use App\Models\Kassa\Income;
use App\Models\Kassa\Product;
use App\Models\Kassa\Refund;
use App\Models\Kassa\Sale;
use App\Models\Kassa\Store;

/**
 * Превращает модели «Кассы и склада» в JSON для клиента. Все суммы — целые сумы.
 */
class Presenter
{
    public static function store(Store $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'address' => $s->address, 'is_demo' => $s->is_demo];
    }

    public static function employee(?Employee $e): ?array
    {
        return $e ? ['id' => $e->id, 'name' => $e->name, 'role' => $e->role, 'phone' => $e->phone, 'active' => $e->active] : null;
    }

    private static function who(?Employee $e): ?array
    {
        return $e ? ['id' => $e->id, 'name' => $e->name] : null;
    }

    public static function product(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'category' => $p->category,
            'barcode' => $p->barcode,
            'buy_price' => $p->buy_price,
            'sell_price' => $p->sell_price,
            'stock' => $p->stock,
            'min_stock' => $p->min_stock,
            'is_low' => $p->isLow(),
            'margin_pct' => $p->buy_price > 0 ? (int) round(($p->sell_price - $p->buy_price) / $p->buy_price * 100) : null,
        ];
    }

    /** Свежие значения изменённых товаров — клиент подставляет их в свой каталог. */
    public static function products(iterable $products): array
    {
        $out = [];
        foreach ($products as $p) {
            $out[] = self::product($p);
        }

        return $out;
    }

    public static function saleSummary(Sale $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'created_at' => Clock::iso($s->created_at),
            'day' => Clock::day($s->created_at),
            'employee' => self::who($s->employee),
            'customer' => $s->customer,
            'payment' => $s->payment,
            'total' => $s->total,
            'units' => (int) ($s->units ?? $s->items->sum('qty')),
            'has_refund' => (bool) ($s->refunds_count ?? $s->refunds->isNotEmpty()),
            'offline' => $s->isOffline(),
            'conflict' => self::saleConflict($s),
        ];
    }

    /**
     * Расхождение офлайн-чека: при синхронизации на складе не хватило товара
     * или сумма на устройстве не совпала с ценами сервера.
     */
    public static function saleConflict(Sale $s): bool
    {
        if (! $s->isOffline()) {
            return false;
        }
        $short = $s->shortage_units ?? ($s->relationLoaded('items') ? $s->items->sum('shortage') : $s->items()->sum('shortage'));

        return (int) $short > 0 || ($s->client_total !== null && $s->client_total !== $s->total);
    }

    public static function sale(Sale $s): array
    {
        $s->loadMissing(['items.refundItems', 'refunds', 'employee']);

        return self::saleSummary($s) + [
            'received' => $s->received,
            'change' => $s->change_amount,
            'synced_at' => Clock::iso($s->synced_at),
            'client_total' => $s->client_total,
            'items' => $s->items->map(function ($i) {
                $returned = (int) $i->refundItems->sum('qty');

                return [
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'name' => $i->name,
                    'qty' => $i->qty,
                    'price' => $i->price,
                    'sum' => $i->qty * $i->price,
                    'returned' => $returned,
                    'returnable' => $i->qty - $returned,
                    'shortage' => $i->shortage,
                ];
            })->values()->all(),
            'refunds' => $s->refunds->sortBy('id')->map(fn (Refund $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'created_at' => Clock::iso($r->created_at),
                'total' => $r->total,
            ])->values()->all(),
        ];
    }

    public static function refund(Refund $r, bool $withItems = true): array
    {
        $r->loadMissing(['sale', 'employee']);
        $data = [
            'id' => $r->id,
            'number' => $r->number,
            'created_at' => Clock::iso($r->created_at),
            'day' => Clock::day($r->created_at),
            'sale_id' => $r->sale_id,
            'sale_number' => $r->sale?->number,
            'employee' => self::who($r->employee),
            'payment' => $r->payment,
            'reason' => $r->reason,
            'total' => $r->total,
        ];
        if ($withItems) {
            $data['items'] = $r->items->map(fn ($i) => ['product_id' => $i->product_id, 'name' => $i->name, 'qty' => $i->qty, 'price' => $i->price, 'sum' => $i->qty * $i->price])->values()->all();
        }

        return $data;
    }

    public static function income(Income $d, bool $withItems = true): array
    {
        $d->loadMissing(['items', 'employee']);
        $data = [
            'id' => $d->id,
            'number' => $d->number,
            'created_at' => Clock::iso($d->created_at),
            'day' => Clock::day($d->created_at),
            'employee' => self::who($d->employee),
            'supplier' => $d->supplier,
            'total' => $d->total,
            'units' => (int) $d->items->sum('qty'),
        ];
        if ($withItems) {
            $data['items'] = $d->items->map(fn ($i) => ['product_id' => $i->product_id, 'name' => $i->name, 'qty' => $i->qty, 'cost' => $i->cost, 'sum' => $i->qty * $i->cost])->values()->all();
        }

        return $data;
    }

    public static function audit(Audit $a, bool $withItems = true): array
    {
        $a->loadMissing(['items', 'employee']);
        $shortQty = $surplusQty = $shortSum = $surplusSum = 0;
        foreach ($a->items as $i) {
            $d = $i->actual - $i->expected;
            if ($d < 0) {
                $shortQty -= $d;
                $shortSum -= $d * $i->cost;
            } elseif ($d > 0) {
                $surplusQty += $d;
                $surplusSum += $d * $i->cost;
            }
        }
        $data = [
            'id' => $a->id,
            'number' => $a->number,
            'created_at' => Clock::iso($a->created_at),
            'day' => Clock::day($a->created_at),
            'employee' => self::who($a->employee),
            'checked' => $a->items->count(),
            'matched' => $a->items->filter(fn ($i) => $i->actual === $i->expected)->count(),
            'shortage_qty' => $shortQty,
            'shortage_sum' => $shortSum,
            'surplus_qty' => $surplusQty,
            'surplus_sum' => $surplusSum,
        ];
        if ($withItems) {
            $data['items'] = $a->items->map(fn ($i) => ['product_id' => $i->product_id, 'name' => $i->name, 'expected' => $i->expected, 'actual' => $i->actual, 'diff' => $i->actual - $i->expected])->values()->all();
        }

        return $data;
    }
}
