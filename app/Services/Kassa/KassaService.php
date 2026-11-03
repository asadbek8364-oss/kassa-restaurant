<?php

namespace App\Services\Kassa;

use App\Models\Kassa\Audit;
use App\Models\Kassa\AuditItem;
use App\Models\Kassa\Employee;
use App\Models\Kassa\Income;
use App\Models\Kassa\IncomeItem;
use App\Models\Kassa\Product;
use App\Models\Kassa\Refund;
use App\Models\Kassa\RefundItem;
use App\Models\Kassa\Sale;
use App\Models\Kassa\SaleItem;
use App\Models\Kassa\Store;
use App\Support\Kassa\Ean13;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Все операции, которые меняют остатки и деньги. Каждая выполняется в одной транзакции:
 * строка магазина блокируется (нумерация документов), строки товаров — lockForUpdate.
 * Цены и суммы берутся только с сервера: клиент присылает лишь товары и количество.
 */
class KassaService
{
    /** Офлайн-чек старше этого срока считается пробитым с неверными часами. */
    public const OFFLINE_MAX_AGE_DAYS = 30;

    /**
     * Продажа: проверяет остатки, считает сумму по розничным ценам из базы, сдачу, списывает товар.
     *
     * @param  array<int, array{product_id:int, qty:int}>  $lines
     * @return array{0: Sale, 1: Collection<int, Product>, 2: bool} [чек, изменённые товары, это повтор запроса]
     */
    public function sell(Employee $by, array $lines, string $payment, ?string $customer, ?int $received, ?string $clientUuid = null): array
    {
        if ($clientUuid && ($existing = Sale::where('client_uuid', $clientUuid)->first())) {
            return [$existing, collect(), true];
        }

        $wanted = $this->sumBy($lines, 'product_id', 'qty');

        try {
            return DB::transaction(function () use ($by, $wanted, $payment, $customer, $received, $clientUuid) {
                $store = Store::locked();
                $products = Product::whereIn('id', array_keys($wanted))->lockForUpdate()->get()->keyBy('id');

                $total = $cost = 0;
                foreach ($wanted as $id => $qty) {
                    $p = $products->get($id) ?? $this->fail('items', 'Товар удалён из каталога — уберите его из чека');
                    if ($qty > $p->stock) {
                        $this->fail('items', "Недостаточно «{$p->name}»: на складе {$p->stock} шт");
                    }
                    $total += $qty * $p->sell_price;
                    $cost += $qty * $p->buy_price;
                }

                $change = 0;
                if ($payment === 'cash') {
                    $received = $received ?: $total;
                    if ($received < $total) {
                        $this->fail('received', 'Получено меньше суммы чека');
                    }
                    $change = $received - $total;
                } else {
                    $received = null;
                }

                $sale = Sale::create([
                    'number' => $store->takeNumber('sale'),
                    'employee_id' => $by->id,
                    'customer' => $customer !== null && trim($customer) !== '' ? trim($customer) : null,
                    'payment' => $payment,
                    'total' => $total,
                    'cost_total' => $cost,
                    'received' => $received,
                    'change_amount' => $change,
                    'client_uuid' => $clientUuid,
                ]);

                foreach ($wanted as $id => $qty) {
                    $p = $products->get($id);
                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $p->id,
                        'name' => $p->name,
                        'qty' => $qty,
                        'price' => $p->sell_price,
                        'cost' => $p->buy_price,
                    ]);
                    $p->stock -= $qty;
                    $p->save();
                }

                return [$sale, $products->values(), false];
            });
        } catch (UniqueConstraintViolationException $e) {
            // тот же чек пришёл дважды одновременно — отдаём уже сохранённый
            $existing = $clientUuid ? Sale::where('client_uuid', $clientUuid)->first() : null;
            if (! $existing) {
                throw $e;
            }

            return [$existing, collect(), true];
        }
    }

    /**
     * Чек, пробитый на устройстве без связи и пришедший позже из очереди.
     *
     * Правило конфликтов по остаткам: такой чек — свершившийся факт (товар у покупателя, деньги в кассе),
     * поэтому сервер принимает его всегда и не отклоняет из-за остатков:
     *  - товар списывается, сколько есть, но остаток не уходит ниже нуля; недостающее количество
     *    записывается в позицию (shortage) — это расхождение, его закрывает приход или инвентаризация;
     *  - цены, себестоимость и итог — по серверу, как у обычного чека; сумма с устройства хранится
     *    в client_total, чтобы расхождение было видно;
     *  - товар, удалённый из каталога, пока чек ждал отправки, записывается по названию и цене с устройства,
     *    без списания (вся строка — shortage);
     *  - время продажи — по часам устройства, если оно не в будущем и не старше OFFLINE_MAX_AGE_DAYS дней;
     *  - номер чека выдаётся при синхронизации; повтор того же client_uuid возвращает уже сохранённый чек.
     *
     * @param  array{client_uuid:string, sold_at:?string, payment:string, customer:?string, received:?int, client_total:?int, items:array<int, array{product_id:int, qty:int, name:?string, price:?int}>}  $data
     * @return array{0: Sale, 1: Collection<int, Product>, 2: bool} [чек, изменённые товары, это повтор]
     */
    public function syncOffline(Employee $by, array $data): array
    {
        $uuid = $data['client_uuid'];
        if ($existing = Sale::withoutGlobalScopes()->where('client_uuid', $uuid)->first()) {
            return [$existing, collect(), true];
        }

        $lines = [];
        foreach ($data['items'] as $l) {
            $id = (int) $l['product_id'];
            $lines[$id] ??= ['qty' => 0, 'name' => trim((string) ($l['name'] ?? '')), 'price' => (int) ($l['price'] ?? 0)];
            $lines[$id]['qty'] += (int) $l['qty'];
        }
        $soldAt = $this->offlineTime($data['sold_at'] ?? null);

        try {
            return DB::transaction(function () use ($by, $data, $lines, $soldAt, $uuid) {
                $store = Store::locked();
                $products = Product::whereIn('id', array_keys($lines))->lockForUpdate()->get()->keyBy('id');

                $rows = [];
                $total = $cost = 0;
                foreach ($lines as $id => $l) {
                    $p = $products->get($id);
                    $row = $p
                        ? ['product_id' => $p->id, 'name' => $p->name, 'price' => $p->sell_price, 'cost' => $p->buy_price, 'shortage' => max(0, $l['qty'] - $p->stock)]
                        : ['product_id' => null, 'name' => mb_substr($l['name'] !== '' ? $l['name'] : 'Удалённый товар', 0, 80), 'price' => $l['price'], 'cost' => 0, 'shortage' => $l['qty']];
                    $rows[] = $row + ['qty' => $l['qty']];
                    $total += $l['qty'] * $row['price'];
                    $cost += $l['qty'] * $row['cost'];
                }

                $received = null;
                $change = 0;
                if ($data['payment'] === 'cash') {
                    $received = (int) ($data['received'] ?? 0) ?: $total;
                    $change = max(0, $received - $total);
                }

                $sale = Sale::create([
                    'number' => $store->takeNumber('sale'),
                    'employee_id' => $by->id,
                    'customer' => isset($data['customer']) && trim($data['customer']) !== '' ? trim($data['customer']) : null,
                    'payment' => $data['payment'],
                    'total' => $total,
                    'cost_total' => $cost,
                    'received' => $received,
                    'change_amount' => $change,
                    'client_uuid' => $uuid,
                    'client_total' => isset($data['client_total']) ? (int) $data['client_total'] : null,
                    'synced_at' => now(),
                    'created_at' => $soldAt,
                ]);

                foreach ($rows as $row) {
                    SaleItem::create(['sale_id' => $sale->id] + $row);
                    if ($row['product_id'] && ($p = $products->get($row['product_id']))) {
                        $p->stock -= $row['qty'] - $row['shortage'];
                        $p->save();
                    }
                }

                return [$sale, $products->values(), false];
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = Sale::withoutGlobalScopes()->where('client_uuid', $uuid)->first();
            if (! $existing) {
                throw $e;
            }

            return [$existing, collect(), true];
        }
    }

    /** Время офлайн-продажи по часам устройства; явно неверное (будущее, старше месяца) заменяется временем сервера. */
    private function offlineTime(?string $value): CarbonImmutable
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        try {
            $t = $value ? CarbonImmutable::parse($value)->setTimezone(config('app.timezone')) : $now;
        } catch (\Throwable) {
            return $now;
        }

        return $t > $now || $t < $now->subDays(self::OFFLINE_MAX_AGE_DAYS) ? $now : $t;
    }

    /**
     * Возврат по чеку: нельзя вернуть больше купленного за вычетом уже возвращённого.
     * Сумма считается по цене из чека, товар возвращается на склад.
     *
     * @param  array<int, array{sale_item_id:int, qty:int}>  $lines
     * @return array{0: Refund, 1: Collection<int, Product>}
     */
    public function refund(Employee $by, Sale $sale, array $lines, string $reason): array
    {
        $wanted = $this->sumBy($lines, 'sale_item_id', 'qty');

        return DB::transaction(function () use ($by, $sale, $wanted, $reason) {
            $store = Store::locked();
            $items = SaleItem::where('sale_id', $sale->id)->whereIn('id', array_keys($wanted))->get()->keyBy('id');
            $returned = RefundItem::whereIn('sale_item_id', $items->keys())
                ->groupBy('sale_item_id')
                ->selectRaw('sale_item_id, SUM(qty) as qty')
                ->pluck('qty', 'sale_item_id');

            $total = $cost = 0;
            foreach ($wanted as $itemId => $qty) {
                $item = $items->get($itemId) ?? $this->fail('items', 'Этого товара нет в чеке №'.$sale->number);
                $left = $item->qty - (int) ($returned[$itemId] ?? 0);
                if ($qty > $left) {
                    $this->fail('items', "Нельзя вернуть больше, чем куплено: «{$item->name}», доступно {$left} шт");
                }
                $total += $qty * $item->price;
                $cost += $qty * $item->cost;
            }

            $productIds = $items->pluck('product_id')->filter()->unique()->all();
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $refund = Refund::create([
                'number' => $store->takeNumber('return'),
                'sale_id' => $sale->id,
                'employee_id' => $by->id,
                'payment' => $sale->payment,
                'reason' => $reason,
                'total' => $total,
                'cost_total' => $cost,
            ]);

            foreach ($wanted as $itemId => $qty) {
                $item = $items->get($itemId);
                RefundItem::create([
                    'return_id' => $refund->id,
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'qty' => $qty,
                    'price' => $item->price,
                    'cost' => $item->cost,
                ]);
                if ($p = $products->get($item->product_id)) {
                    $p->stock += $qty;
                    $p->save();
                }
            }

            return [$refund, $products->values()];
        });
    }

    /**
     * Приход: увеличивает остатки; ненулевая закупочная цена становится текущей ценой товара.
     *
     * @param  array<int, array{product_id:int, qty:int, cost:int}>  $lines
     * @return array{0: Income, 1: Collection<int, Product>}
     */
    public function receive(Employee $by, ?string $supplier, array $lines): array
    {
        $qty = $this->sumBy($lines, 'product_id', 'qty');
        $cost = [];
        foreach ($lines as $l) {
            $cost[(int) $l['product_id']] = (int) $l['cost'];
        }

        return DB::transaction(function () use ($by, $supplier, $qty, $cost) {
            $store = Store::locked();
            $products = Product::whereIn('id', array_keys($qty))->lockForUpdate()->get()->keyBy('id');
            foreach (array_keys($qty) as $id) {
                $products->get($id) ?? $this->fail('items', 'Товар удалён из каталога — уберите его из прихода');
            }

            $income = Income::create([
                'number' => $store->takeNumber('income'),
                'employee_id' => $by->id,
                'supplier' => $supplier !== null && trim($supplier) !== '' ? trim($supplier) : null,
                'total' => 0,
            ]);

            $total = 0;
            foreach ($qty as $id => $q) {
                $p = $products->get($id);
                IncomeItem::create(['income_id' => $income->id, 'product_id' => $id, 'name' => $p->name, 'qty' => $q, 'cost' => $cost[$id]]);
                $total += $q * $cost[$id];
                $p->stock += $q;
                if ($cost[$id] > 0) {
                    $p->buy_price = $cost[$id];
                }
                $p->save();
            }
            $income->update(['total' => $total]);

            return [$income, $products->values()];
        });
    }

    /**
     * Инвентаризация: учётный остаток фиксируется на момент проведения, затем становится равен факту.
     *
     * @param  array<int, array{product_id:int, actual:int}>  $counts
     * @return array{0: Audit, 1: Collection<int, Product>}
     */
    public function audit(Employee $by, array $counts): array
    {
        $actual = [];
        foreach ($counts as $c) {
            $actual[(int) $c['product_id']] = (int) $c['actual'];
        }

        return DB::transaction(function () use ($by, $actual) {
            $store = Store::locked();
            $products = Product::whereIn('id', array_keys($actual))->lockForUpdate()->get()->keyBy('id');
            $audit = Audit::create(['number' => $store->takeNumber('audit'), 'employee_id' => $by->id]);

            foreach ($actual as $id => $count) {
                $p = $products->get($id);
                if (! $p) {
                    continue; // товар удалили, пока шёл пересчёт
                }
                AuditItem::create([
                    'audit_id' => $audit->id,
                    'product_id' => $p->id,
                    'name' => $p->name,
                    'expected' => $p->stock,
                    'actual' => $count,
                    'cost' => $p->buy_price,
                ]);
                $p->stock = $count;
                $p->save();
            }

            return [$audit, $products->values()];
        });
    }

    /** Новый товар; начальный остаток оформляется приходом «Начальный остаток». */
    public function createProduct(Employee $by, array $data, int $openingStock): Product
    {
        return DB::transaction(function () use ($by, $data, $openingStock) {
            $product = Product::create($data + ['stock' => 0]);
            if ($openingStock > 0) {
                $this->receive($by, Income::OPENING_SUPPLIER, [['product_id' => $product->id, 'qty' => $openingStock, 'cost' => $product->buy_price]]);
            }

            return $product->refresh();
        });
    }

    public function newBarcode(): string
    {
        do {
            $code = Ean13::internal();
        } while (Product::where('barcode', $code)->exists());

        return $code;
    }

    /** Склеивает повторяющиеся строки (один товар дважды) в одну с суммой количества. */
    private function sumBy(array $lines, string $key, string $field): array
    {
        $out = [];
        foreach ($lines as $l) {
            $id = (int) $l[$key];
            $out[$id] = ($out[$id] ?? 0) + (int) $l[$field];
        }

        return $out;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
