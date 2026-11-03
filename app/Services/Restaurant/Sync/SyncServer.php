<?php

namespace App\Services\Restaurant\Sync;

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
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\Table;
use App\Support\Restaurant\SyncGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Онлайн-сервер: приём данных локального сервера ресторана (push) и выдача справочников (pull).
 * Работает в контексте ресторана, которому выдан sync-токен.
 *
 * push — только добавление: заказы, продажи, возвраты, приходы и инвентаризации с известным uuid уже приняты
 * и не перезаписываются (повторная отправка — «дубль», не ошибка). Остатки товаров склада — снимок с локального
 * сервера (склад ведётся там). Изменения справочников с локального сервера принимаются, только если запись
 * на онлайн-сервере не менялась с тех пор, как локальный сервер её получил (версия совпадает), иначе
 * побеждает онлайн-версия, и локальный сервер получит её при pull.
 */
class SyncServer
{
    /** uuid → id в этом ресторане, чтобы не искать одно и то же много раз. */
    private array $ids = [];

    public function pull(): array
    {
        $store = Store::current();
        $categories = Category::withTrashed()->orderBy('id')->get();
        $categoryUuids = $categories->pluck('uuid', 'id')->all();

        return [
            'format' => SyncFormat::VERSION,
            'server_time' => now()->toIso8601String(),
            'settings' => SyncFormat::settingsRow(Settings::current(), $store),
            'categories' => $categories->map(fn ($c) => SyncFormat::categoryRow($c))->all(),
            'dishes' => Dish::withTrashed()->orderBy('id')->get()->map(fn ($d) => SyncFormat::dishRow($d, $categoryUuids))->all(),
            'tables' => Table::withTrashed()->orderBy('id')->get()->map(fn ($t) => SyncFormat::tableRow($t))->all(),
            'staff' => Employee::whereNotNull('store_id')->whereIn('role', SyncFormat::STAFF_ROLES)->orderBy('id')->get()
                ->map(fn ($e) => SyncFormat::staffRow($e))->all(),
        ];
    }

    public function push(array $data): array
    {
        if ((int) ($data['format'] ?? 0) !== SyncFormat::VERSION) {
            throw new SyncException('Неизвестный формат синхронизации — обновите программу на обоих серверах до одной версии');
        }
        $this->ids = [];
        $result = [
            'master' => ['accepted' => [], 'conflicts' => []],
            'products' => 0,
            'accepted' => ['sales' => [], 'returns' => [], 'incomes' => [], 'audits' => [], 'orders' => []],
            'counts' => [],
            'rejected' => [],
        ];

        // по ссылке: стрелочные функции захватывают переменные по значению
        DB::transaction(function () use ($data, &$result) {
            SyncGate::apply(function () use ($data, &$result) {
                $this->applyMaster((array) ($data['master'] ?? []), $result);
            });
        });
        $result['products'] = DB::transaction(function () use ($data, &$result) {
            return $this->applyProducts((array) ($data['products'] ?? []), $result);
        });

        foreach (['sales', 'returns', 'incomes', 'audits', 'orders'] as $type) {
            $created = $duplicate = 0;
            foreach ((array) ($data[$type] ?? []) as $doc) {
                $uuid = is_array($doc) ? ($doc['uuid'] ?? null) : null;
                if (! SyncFormat::isUuid($uuid)) {
                    $result['rejected'][] = ['type' => $type, 'uuid' => is_string($uuid) ? $uuid : null, 'message' => 'Нет uuid'];

                    continue;
                }
                try {
                    $status = DB::transaction(fn () => $this->applyDoc($type, $doc));
                    $status === 'created' ? $created++ : $duplicate++;
                    $result['accepted'][$type][] = $uuid;
                } catch (SyncException $e) {
                    $result['rejected'][] = ['type' => $type, 'uuid' => $uuid, 'message' => $e->getMessage()];
                }
            }
            $result['counts'][$type] = ['created' => $created, 'duplicate' => $duplicate];
        }

        return $result;
    }

    // ---------------------------------------------------------------- справочники с локального сервера

    private function applyMaster(array $master, array &$result): void
    {
        $accept = function (string $uuid) use (&$result) {
            $result['master']['accepted'][] = $uuid;
        };
        $conflict = function (string $uuid) use (&$result) {
            $result['master']['conflicts'][] = $uuid;
        };

        foreach ((array) ($master['categories'] ?? []) as $r) {
            $this->proposal(Category::class, $r, fn (Category $m) => SyncFormat::fillCategory($m, $r), $accept, $conflict);
        }
        foreach ((array) ($master['dishes'] ?? []) as $r) {
            $this->proposal(Dish::class, $r, fn (Dish $m) => SyncFormat::fillDish($m, $r, $this->id(Category::class, $r['category_uuid'] ?? null, true)), $accept, $conflict);
        }
        foreach ((array) ($master['tables'] ?? []) as $r) {
            $this->proposal(Table::class, $r, fn (Table $m) => SyncFormat::fillTable($m, $r), $accept, $conflict);
        }
        foreach ((array) ($master['staff'] ?? []) as $r) {
            if (! in_array($r['role'] ?? null, SyncFormat::STAFF_ROLES, true) || ! is_string($r['pin'] ?? null)) {
                $conflict((string) ($r['uuid'] ?? ''));

                continue;
            }
            $this->proposal(Employee::class, $r, fn (Employee $m) => SyncFormat::fillStaff($m, $r), $accept, $conflict);
        }

        $s = $master['settings'] ?? null;
        if (is_array($s) && SyncFormat::isUuid($s['uuid'] ?? null)) {
            $settings = Settings::current();
            if ($settings->uuid === $s['uuid'] && (int) $settings->version === (int) ($s['version'] ?? 0)) {
                $store = Store::current();
                SyncFormat::fillSettings($settings, $store, $s);
                $settings->version++;
                $settings->save();
                $store->save();
                $accept($s['uuid']);
            } else {
                $conflict($s['uuid']);
            }
        }
    }

    /**
     * Правка справочника с локального сервера. Новая запись создаётся; существующая меняется, только если её версия
     * на онлайн-сервере та же, что видел локальный сервер; иначе — конфликт, побеждает онлайн-версия.
     */
    private function proposal(string $model, array $r, \Closure $fill, \Closure $accept, \Closure $conflict): void
    {
        $uuid = $r['uuid'] ?? null;
        if (! SyncFormat::isUuid($uuid)) {
            return;
        }
        $soft = $model !== Employee::class;
        $query = $soft ? $model::withTrashed() : $model::query();
        /** @var Model|null $row */
        $row = $query->where('uuid', $uuid)->first();
        $deleted = (bool) ($r['deleted'] ?? false);

        if (! $row) {
            if ($model::withoutGlobalScopes()->where('uuid', $uuid)->exists()) {
                $conflict($uuid); // такой uuid есть у другого ресторана

                return;
            }
            if ($deleted) {
                $accept($uuid); // создали и удалили на локальном сервере — здесь ничего не нужно

                return;
            }
            $row = new $model;
            $row->uuid = $uuid;
            $row->version = 1;
            $fill($row);
            $row->save();
            $accept($uuid);

            return;
        }
        if ((int) $row->version !== (int) ($r['version'] ?? 0)) {
            $conflict($uuid);

            return;
        }
        $fill($row);
        if ($soft) {
            SyncFormat::markDeleted($row, $deleted);
        }
        $row->version = (int) $row->version + 1;
        $row->save();
        $accept($uuid);
    }

    /** Снимок склада с локального сервера: товары с остатками (склад ведётся там — локальная версия главная). */
    private function applyProducts(array $products, array &$result): int
    {
        $n = 0;
        foreach ($products as $p) {
            if (! is_array($p) || ! SyncFormat::isUuid($p['uuid'] ?? null)) {
                continue;
            }
            $row = Product::where('uuid', $p['uuid'])->first();
            if (! $row && Product::withoutGlobalScopes()->where('uuid', $p['uuid'])->exists()) {
                continue;
            }
            $barcode = (string) ($p['barcode'] ?? '');
            if (Product::where('barcode', $barcode)->when($row, fn ($q) => $q->whereKeyNot($row->id))->exists()) {
                $result['rejected'][] = ['type' => 'products', 'uuid' => $p['uuid'], 'message' => "Штрих-код {$barcode} уже занят другим товаром"];

                continue;
            }
            $row ??= new Product(['uuid' => $p['uuid']]);
            $row->fill([
                'name' => SyncFormat::str($p['name'] ?? '', 80) ?? 'Товар',
                'category' => in_array($p['category'] ?? null, Product::CATEGORIES, true) ? $p['category'] : 'accessories',
                'barcode' => $barcode,
                'buy_price' => SyncFormat::int($p['buy_price'] ?? 0),
                'sell_price' => SyncFormat::int($p['sell_price'] ?? 0),
                'stock' => SyncFormat::int($p['stock'] ?? 0),
                'min_stock' => SyncFormat::int($p['min_stock'] ?? 0),
            ]);
            $row->save();
            $n++;
        }

        return $n;
    }

    // ---------------------------------------------------------------- документы: только добавление

    /** @return 'created'|'duplicate' */
    private function applyDoc(string $type, array $d): string
    {
        $model = [
            'sales' => Sale::class, 'returns' => Refund::class, 'incomes' => Income::class,
            'audits' => Audit::class, 'orders' => Order::class,
        ][$type];
        // запись с этим uuid уже принята — онлайн-сервер её не перезаписывает
        if ($model::withoutGlobalScopes()->where('uuid', $d['uuid'])->exists()) {
            return 'duplicate';
        }
        $items = array_values(array_filter((array) ($d['items'] ?? []), 'is_array'));
        $store = Store::locked();
        $times = ['created_at' => SyncFormat::parseTime($d['created_at'] ?? null) ?? now(), 'updated_at' => SyncFormat::parseTime($d['updated_at'] ?? null) ?? now()];

        match ($type) {
            'sales' => $this->saveSale($store, $d, $items, $times),
            'returns' => $this->saveReturn($store, $d, $items, $times),
            'incomes' => $this->saveIncome($store, $d, $items, $times),
            'audits' => $this->saveAudit($store, $d, $items, $times),
            'orders' => $this->saveOrder($d, $items, $times),
        };

        return 'created';
    }

    private function saveSale(Store $store, array $d, array $items, array $times): void
    {
        $payment = in_array($d['payment'] ?? null, Sale::PAYMENTS, true) ? $d['payment'] : 'cash';
        $sale = Sale::create([
            'uuid' => $d['uuid'],
            'number' => $this->number(Sale::class, 'sale', $d, $store),
            'employee_id' => $this->id(Employee::class, $d['employee_uuid'] ?? null),
            'customer' => SyncFormat::str($d['customer'] ?? null, 60),
            'payment' => $payment,
            'total' => SyncFormat::int($d['total'] ?? 0),
            'cost_total' => SyncFormat::int($d['cost_total'] ?? 0),
            'received' => isset($d['received']) ? SyncFormat::int($d['received']) : null,
            'change_amount' => SyncFormat::int($d['change_amount'] ?? 0),
            'client_uuid' => SyncFormat::isUuid($d['client_uuid'] ?? null) && ! Sale::withoutGlobalScopes()->where('client_uuid', $d['client_uuid'])->exists() ? $d['client_uuid'] : null,
            'client_total' => isset($d['client_total']) ? SyncFormat::int($d['client_total']) : null,
            'synced_at' => SyncFormat::parseTime($d['synced_at'] ?? null),
        ] + $times);
        foreach ($items as $i) {
            SaleItem::create([
                'uuid' => SyncFormat::isUuid($i['uuid'] ?? null) && ! SaleItem::withoutGlobalScopes()->where('uuid', $i['uuid'])->exists() ? $i['uuid'] : null,
                'sale_id' => $sale->id,
                'product_id' => $this->id(Product::class, $i['product_uuid'] ?? null),
                'name' => SyncFormat::str($i['name'] ?? '', 80) ?? 'Позиция',
                'qty' => max(1, SyncFormat::int($i['qty'] ?? 1)),
                'price' => SyncFormat::int($i['price'] ?? 0),
                'cost' => SyncFormat::int($i['cost'] ?? 0),
                'shortage' => SyncFormat::int($i['shortage'] ?? 0),
            ]);
        }
    }

    private function saveReturn(Store $store, array $d, array $items, array $times): void
    {
        $saleId = $this->id(Sale::class, $d['sale_uuid'] ?? null);
        if (! $saleId) {
            throw new SyncException('Чек этого возврата ещё не получен онлайн-сервером');
        }
        $lines = [];
        foreach ($items as $i) {
            $saleItemId = $this->id(SaleItem::class, $i['sale_item_uuid'] ?? null);
            if (! $saleItemId) {
                throw new SyncException('Позиция чека этого возврата не найдена на онлайн-сервере');
            }
            $lines[] = [$i, $saleItemId];
        }
        $refund = Refund::create([
            'uuid' => $d['uuid'],
            'number' => $this->number(Refund::class, 'return', $d, $store),
            'sale_id' => $saleId,
            'employee_id' => $this->id(Employee::class, $d['employee_uuid'] ?? null),
            'payment' => in_array($d['payment'] ?? null, Sale::PAYMENTS, true) ? $d['payment'] : 'cash',
            'reason' => SyncFormat::str($d['reason'] ?? null, 40) ?? 'Другое',
            'total' => SyncFormat::int($d['total'] ?? 0),
            'cost_total' => SyncFormat::int($d['cost_total'] ?? 0),
        ] + $times);
        foreach ($lines as [$i, $saleItemId]) {
            RefundItem::create([
                'return_id' => $refund->id,
                'sale_item_id' => $saleItemId,
                'product_id' => $this->id(Product::class, $i['product_uuid'] ?? null),
                'name' => SyncFormat::str($i['name'] ?? '', 80) ?? 'Позиция',
                'qty' => max(1, SyncFormat::int($i['qty'] ?? 1)),
                'price' => SyncFormat::int($i['price'] ?? 0),
                'cost' => SyncFormat::int($i['cost'] ?? 0),
            ]);
        }
    }

    private function saveIncome(Store $store, array $d, array $items, array $times): void
    {
        $income = Income::create([
            'uuid' => $d['uuid'],
            'number' => $this->number(Income::class, 'income', $d, $store),
            'employee_id' => $this->id(Employee::class, $d['employee_uuid'] ?? null),
            'supplier' => SyncFormat::str($d['supplier'] ?? null, 60),
            'total' => SyncFormat::int($d['total'] ?? 0),
        ] + $times);
        foreach ($items as $i) {
            IncomeItem::create([
                'income_id' => $income->id,
                'product_id' => $this->id(Product::class, $i['product_uuid'] ?? null),
                'name' => SyncFormat::str($i['name'] ?? '', 80) ?? 'Товар',
                'qty' => SyncFormat::int($i['qty'] ?? 0),
                'cost' => SyncFormat::int($i['cost'] ?? 0),
            ]);
        }
    }

    private function saveAudit(Store $store, array $d, array $items, array $times): void
    {
        $audit = Audit::create([
            'uuid' => $d['uuid'],
            'number' => $this->number(Audit::class, 'audit', $d, $store),
            'employee_id' => $this->id(Employee::class, $d['employee_uuid'] ?? null),
        ] + $times);
        foreach ($items as $i) {
            AuditItem::create([
                'audit_id' => $audit->id,
                'product_id' => $this->id(Product::class, $i['product_uuid'] ?? null),
                'name' => SyncFormat::str($i['name'] ?? '', 80) ?? 'Товар',
                'expected' => SyncFormat::int($i['expected'] ?? 0),
                'actual' => SyncFormat::int($i['actual'] ?? 0),
                'cost' => SyncFormat::int($i['cost'] ?? 0),
            ]);
        }
    }

    private function saveOrder(array $d, array $items, array $times): void
    {
        if (! in_array($d['status'] ?? null, Order::FINAL, true)) {
            throw new SyncException('Принимаются только завершённые заказы (оплаченные или отменённые)');
        }
        $order = new Order;
        $order->forceFill([
            'uuid' => $d['uuid'],
            'number' => max(1, SyncFormat::int($d['number'] ?? 1)),
            'table_id' => $this->id(Table::class, $d['table_uuid'] ?? null, true),
            'table_name' => SyncFormat::str($d['table_name'] ?? null, 20),
            'waiter_id' => $this->id(Employee::class, $d['waiter_uuid'] ?? null),
            'waiter_name' => SyncFormat::str($d['waiter_name'] ?? null, 60),
            'guests' => isset($d['guests']) ? SyncFormat::int($d['guests']) : null,
            'comment' => SyncFormat::str($d['comment'] ?? null, 200),
            'status' => $d['status'],
            'total' => SyncFormat::int($d['total'] ?? 0),
            'payment' => in_array($d['payment'] ?? null, Order::PAYMENTS, true) ? $d['payment'] : null,
            'received' => isset($d['received']) ? SyncFormat::int($d['received']) : null,
            'change_amount' => SyncFormat::int($d['change_amount'] ?? 0),
            'sale_id' => $this->id(Sale::class, $d['sale_uuid'] ?? null),
            'closed_by' => $this->id(Employee::class, $d['closed_by_uuid'] ?? null),
            'bill_at' => SyncFormat::parseTime($d['bill_at'] ?? null),
            'paid_at' => SyncFormat::parseTime($d['paid_at'] ?? null),
            'cancelled_at' => SyncFormat::parseTime($d['cancelled_at'] ?? null),
            'cancel_reason' => SyncFormat::str($d['cancel_reason'] ?? null, 100),
        ] + $times)->save();

        foreach ($items as $i) {
            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id,
                'batch' => max(1, SyncFormat::int($i['batch'] ?? 1)),
                'dish_id' => $this->id(Dish::class, $i['dish_uuid'] ?? null, true),
                'name' => SyncFormat::str($i['name'] ?? '', 80) ?? 'Блюдо',
                'price' => SyncFormat::int($i['price'] ?? 0),
                'qty' => max(1, SyncFormat::int($i['qty'] ?? 1)),
                'comment' => SyncFormat::str($i['comment'] ?? null, 120),
                'status' => in_array($i['status'] ?? null, OrderItem::STATUSES, true) ? $i['status'] : 'served',
                'sent_at' => SyncFormat::parseTime($i['sent_at'] ?? null),
                'cooking_at' => SyncFormat::parseTime($i['cooking_at'] ?? null),
                'ready_at' => SyncFormat::parseTime($i['ready_at'] ?? null),
                'served_at' => SyncFormat::parseTime($i['served_at'] ?? null),
                'cancelled_at' => SyncFormat::parseTime($i['cancelled_at'] ?? null),
            ])->save();
        }
    }

    /**
     * Номер документа с локального сервера. Если в этом ресторане он уже занят (документ создан прямо здесь),
     * выдаётся следующий свободный; счётчик ресторана всегда остаётся впереди принятых номеров.
     */
    private function number(string $model, string $kind, array $d, Store $store): int
    {
        $wanted = SyncFormat::int($d['number'] ?? 0);
        $column = "next_{$kind}_no";
        if ($wanted > 0 && ! $model::where('number', $wanted)->exists()) {
            if ($store->{$column} <= $wanted) {
                $store->{$column} = $wanted + 1;
                $store->save();
            }

            return $wanted;
        }

        return $store->takeNumber($kind);
    }

    /** id записи этого ресторана по uuid (или null). */
    private function id(string $model, mixed $uuid, bool $withTrashed = false): ?int
    {
        if (! SyncFormat::isUuid($uuid)) {
            return null;
        }
        $key = $model.'|'.$uuid;
        if (! array_key_exists($key, $this->ids)) {
            $query = $withTrashed ? $model::withTrashed() : $model::query();
            if ($model === Employee::class) {
                $query = Employee::withoutGlobalScopes()->where(fn ($q) => $q->where('store_id', Store::current()->id)->orWhereNull('store_id'));
            }
            $this->ids[$key] = $query->where('uuid', $uuid)->value('id');
        }

        return $this->ids[$key] !== null ? (int) $this->ids[$key] : null;
    }
}
