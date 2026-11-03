<?php

namespace App\Services\Restaurant\Sync;

use App\Models\Kassa\Audit;
use App\Models\Kassa\Employee;
use App\Models\Kassa\Income;
use App\Models\Kassa\Product;
use App\Models\Kassa\Refund;
use App\Models\Kassa\Sale;
use App\Models\Kassa\SaleItem;
use App\Models\Kassa\Store;
use App\Models\Kassa\Token;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\SyncLog;
use App\Models\Restaurant\Table;
use App\Support\Kassa\StoreContext;
use App\Support\Restaurant\Mode;
use App\Support\Restaurant\SyncGate;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Локальный сервер: синхронизация с онлайн-сервером (раз в день по расписанию и по кнопке в админке).
 *
 * 1. Вверх (push): завершённые заказы, продажи, возвраты, приходы и инвентаризации, ещё не принятые онлайн-сервером
 *    (synced_at / cloud_synced_at пусты), снимок склада и локальные правки справочников. Пачками; принятое помечается.
 *    Не прошло — остаётся неотправленным и уйдёт при следующем запуске.
 * 2. Вниз (pull): меню, цены, столики, сотрудники и настройки. Онлайн-версия побеждает: запись заменяется,
 *    если на онлайн-сервере она новее той, что видел этот сервер. Нетронутая там локальная правка сохраняется
 *    и уходит вверх при следующем push.
 */
class SyncClient
{
    private const DOC_TYPES = ['sales', 'returns', 'incomes', 'audits', 'orders'];

    public function __construct(private readonly SyncTransportFactory $transports) {}

    public function run(Store $store, string $trigger = 'manual'): SyncLog
    {
        return app(StoreContext::class)->run($store, fn () => $this->runHere($trigger));
    }

    private function runHere(string $trigger): SyncLog
    {
        $log = SyncLog::create(['direction' => 'sync', 'trigger' => $trigger, 'status' => 'running', 'started_at' => now()]);
        $sent = array_fill_keys([...self::DOC_TYPES, 'products', 'menu', 'conflicts', 'rejected'], 0);
        $received = [];
        try {
            if (Mode::online()) {
                throw new SyncException('Это онлайн-сервер: синхронизацию запускает локальный сервер ресторана');
            }
            $transport = $this->transports->make(Settings::current());
            $this->ensureUuids();

            $rejected = [];
            $limit = max(10, (int) config('restaurant.sync.batch', 200));
            for ($round = 0; $round < 1000; $round++) {
                [$payload, $manifest, $more] = $this->buildPush($limit, $round === 0);
                if ($manifest['empty']) {
                    break;
                }
                $response = $transport->push($payload);
                $accepted = $this->markDelivered($manifest, $response);
                foreach (self::DOC_TYPES as $type) {
                    $sent[$type] += count($response['accepted'][$type] ?? []);
                }
                $sent['products'] += (int) ($response['products'] ?? 0);
                $sent['menu'] += count($response['master']['accepted'] ?? []);
                $sent['conflicts'] += count($response['master']['conflicts'] ?? []);
                $sent['rejected'] += count($response['rejected'] ?? []);
                $rejected = array_merge($rejected, (array) ($response['rejected'] ?? []));
                if (! $more || $accepted === 0) {
                    break;
                }
            }

            $received = $this->applyPull($transport->pull());
            $log->update([
                'status' => $rejected ? 'failed' : 'ok',
                'sent' => $sent,
                'received' => $received,
                'error' => $rejected ? 'Онлайн-сервер не принял '.count($rejected).' зап.: '
                    .collect($rejected)->pluck('message')->filter()->unique()->take(3)->implode('; ') : null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            if (! $e instanceof SyncException) {
                report($e);
            }
            $log->update(['status' => 'failed', 'sent' => $sent, 'received' => $received, 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
        }

        return $log->refresh();
    }

    /** Проверка связи и токена (кнопка «Проверить связь»). */
    public function ping(): array
    {
        return $this->transports->make(Settings::current())->ping();
    }

    /** Сколько записей ещё не принято онлайн-сервером. */
    public function pending(): array
    {
        return [
            'orders' => Order::whereIn('status', Order::FINAL)->whereNull('synced_at')->count(),
            'sales' => Sale::whereNull('cloud_synced_at')->count(),
            'stock' => Refund::whereNull('cloud_synced_at')->count() + Income::whereNull('cloud_synced_at')->count()
                + Audit::whereNull('cloud_synced_at')->count(),
            'menu' => Category::withTrashed()->whereNull('synced_at')->count() + Dish::withTrashed()->whereNull('synced_at')->count()
                + Table::withTrashed()->whereNull('synced_at')->count()
                + Employee::whereNotNull('store_id')->whereNull('synced_at')->count()
                + (Settings::current()->synced_at === null ? 1 : 0),
        ];
    }

    // ---------------------------------------------------------------- вверх

    /**
     * Пакет для онлайн-сервера.
     *
     * @return array{0: array, 1: array, 2: bool} [пакет, что помечать после ответа, есть ли ещё документы]
     */
    private function buildPush(int $limit, bool $first): array
    {
        $manifest = ['master' => [], 'empty' => true];
        $payload = ['format' => SyncFormat::VERSION, 'master' => [], 'products' => []];

        if ($first) {
            $payload['master'] = $this->masterChanges($manifest);
            $payload['products'] = Product::orderBy('id')->get()->map(fn (Product $p) => [
                'uuid' => $p->uuid, 'name' => $p->name, 'category' => $p->category, 'barcode' => $p->barcode,
                'buy_price' => $p->buy_price, 'sell_price' => $p->sell_price, 'stock' => $p->stock, 'min_stock' => $p->min_stock,
            ])->all();
            $manifest['empty'] = $manifest['master'] === [] && $payload['products'] === [];
        }

        $orders = Order::with('items')->whereIn('status', Order::FINAL)->whereNull('synced_at')->orderBy('id')->limit($limit)->get();
        // продажи этих заказов — в том же пакете, чтобы онлайн-сервер связал заказ с продажей
        $saleIds = $orders->pluck('sale_id')->filter()->all();
        $sales = Sale::with('items')->whereNull('cloud_synced_at')->orderBy('id')->limit($limit)->get();
        $sales = $sales->concat(Sale::with('items')->whereNull('cloud_synced_at')->whereIn('id', $saleIds)->whereNotIn('id', $sales->pluck('id'))->get());
        $returns = Refund::with('items')->whereNull('cloud_synced_at')->orderBy('id')->limit($limit)->get();
        $incomes = Income::with('items')->whereNull('cloud_synced_at')->orderBy('id')->limit($limit)->get();
        $audits = Audit::with('items')->whereNull('cloud_synced_at')->orderBy('id')->limit($limit)->get();

        $uuids = $this->uuidMaps($sales, $returns, $incomes, $audits, $orders);
        $employee = fn ($id) => $id ? ($uuids['employees'][$id] ?? null) : null;
        $product = fn ($id) => $id ? ($uuids['products'][$id] ?? null) : null;

        $payload['sales'] = $sales->map(fn (Sale $s) => [
            'uuid' => $s->uuid, 'number' => $s->number, 'employee_uuid' => $employee($s->employee_id),
            'customer' => $s->customer, 'payment' => $s->payment, 'total' => $s->total, 'cost_total' => $s->cost_total,
            'received' => $s->received, 'change_amount' => $s->change_amount, 'client_uuid' => $s->client_uuid,
            'client_total' => $s->client_total, 'synced_at' => SyncFormat::time($s->synced_at),
            'created_at' => SyncFormat::time($s->created_at), 'updated_at' => SyncFormat::time($s->updated_at),
            'items' => $s->items->map(fn ($i) => [
                'uuid' => $i->uuid, 'product_uuid' => $product($i->product_id), 'name' => $i->name, 'qty' => $i->qty,
                'price' => $i->price, 'cost' => $i->cost, 'shortage' => $i->shortage,
            ])->values()->all(),
        ])->values()->all();
        $payload['returns'] = $returns->map(fn (Refund $r) => [
            'uuid' => $r->uuid, 'number' => $r->number, 'sale_uuid' => $uuids['sales'][$r->sale_id] ?? null,
            'employee_uuid' => $employee($r->employee_id), 'payment' => $r->payment, 'reason' => $r->reason,
            'total' => $r->total, 'cost_total' => $r->cost_total,
            'created_at' => SyncFormat::time($r->created_at), 'updated_at' => SyncFormat::time($r->updated_at),
            'items' => $r->items->map(fn ($i) => [
                'sale_item_uuid' => $uuids['sale_items'][$i->sale_item_id] ?? null, 'product_uuid' => $product($i->product_id),
                'name' => $i->name, 'qty' => $i->qty, 'price' => $i->price, 'cost' => $i->cost,
            ])->values()->all(),
        ])->values()->all();
        $payload['incomes'] = $incomes->map(fn (Income $d) => [
            'uuid' => $d->uuid, 'number' => $d->number, 'employee_uuid' => $employee($d->employee_id), 'supplier' => $d->supplier,
            'total' => $d->total, 'created_at' => SyncFormat::time($d->created_at), 'updated_at' => SyncFormat::time($d->updated_at),
            'items' => $d->items->map(fn ($i) => ['product_uuid' => $product($i->product_id), 'name' => $i->name, 'qty' => $i->qty, 'cost' => $i->cost])->values()->all(),
        ])->values()->all();
        $payload['audits'] = $audits->map(fn (Audit $a) => [
            'uuid' => $a->uuid, 'number' => $a->number, 'employee_uuid' => $employee($a->employee_id),
            'created_at' => SyncFormat::time($a->created_at), 'updated_at' => SyncFormat::time($a->updated_at),
            'items' => $a->items->map(fn ($i) => [
                'product_uuid' => $product($i->product_id), 'name' => $i->name, 'expected' => $i->expected, 'actual' => $i->actual, 'cost' => $i->cost,
            ])->values()->all(),
        ])->values()->all();
        $payload['orders'] = $orders->map(fn (Order $o) => [
            'uuid' => $o->uuid, 'number' => $o->number, 'table_uuid' => $uuids['tables'][$o->table_id] ?? null, 'table_name' => $o->table_name,
            'waiter_uuid' => $employee($o->waiter_id), 'waiter_name' => $o->waiter_name, 'guests' => $o->guests, 'comment' => $o->comment,
            'status' => $o->status, 'total' => $o->total, 'payment' => $o->payment, 'received' => $o->received,
            'change_amount' => $o->change_amount, 'sale_uuid' => $uuids['sales'][$o->sale_id] ?? null,
            'closed_by_uuid' => $employee($o->closed_by), 'bill_at' => SyncFormat::time($o->bill_at),
            'paid_at' => SyncFormat::time($o->paid_at), 'cancelled_at' => SyncFormat::time($o->cancelled_at),
            'cancel_reason' => $o->cancel_reason,
            'created_at' => SyncFormat::time($o->created_at), 'updated_at' => SyncFormat::time($o->updated_at),
            'items' => $o->items->sortBy('id')->map(fn (OrderItem $i) => [
                'dish_uuid' => $uuids['dishes'][$i->dish_id] ?? null, 'batch' => $i->batch, 'name' => $i->name, 'price' => $i->price,
                'qty' => $i->qty, 'comment' => $i->comment, 'status' => $i->status,
                'sent_at' => SyncFormat::time($i->sent_at), 'cooking_at' => SyncFormat::time($i->cooking_at),
                'ready_at' => SyncFormat::time($i->ready_at), 'served_at' => SyncFormat::time($i->served_at),
                'cancelled_at' => SyncFormat::time($i->cancelled_at),
            ])->values()->all(),
        ])->values()->all();

        $docs = 0;
        $more = false;
        foreach (self::DOC_TYPES as $type) {
            $docs += count($payload[$type]);
            $more = $more || count($payload[$type]) >= $limit;
        }
        $manifest['empty'] = $manifest['empty'] && $docs === 0;

        return [$payload, $manifest, $more];
    }

    /** Локальные правки справочников (synced_at пуст). В манифест — uuid и updated_at, чтобы не пометить правку, сделанную во время отправки. */
    private function masterChanges(array &$manifest): array
    {
        $note = function (Model $m) use (&$manifest) {
            $manifest['master'][] = [get_class($m), $m->uuid, $m->getRawOriginal('updated_at')];
        };
        $categoryUuids = Category::withTrashed()->pluck('uuid', 'id')->all();
        $master = [
            'categories' => Category::withTrashed()->whereNull('synced_at')->orderBy('id')->get()
                ->each($note)->map(fn ($c) => SyncFormat::categoryRow($c))->values()->all(),
            'dishes' => Dish::withTrashed()->whereNull('synced_at')->orderBy('id')->get()
                ->each($note)->map(fn ($d) => SyncFormat::dishRow($d, $categoryUuids))->values()->all(),
            'tables' => Table::withTrashed()->whereNull('synced_at')->orderBy('id')->get()
                ->each($note)->map(fn ($t) => SyncFormat::tableRow($t))->values()->all(),
            'staff' => Employee::whereNotNull('store_id')->whereIn('role', SyncFormat::STAFF_ROLES)->whereNull('synced_at')->orderBy('id')->get()
                ->each($note)->map(fn ($e) => SyncFormat::staffRow($e))->values()->all(),
        ];
        $settings = Settings::current();
        if ($settings->synced_at === null) {
            $note($settings);
            $master['settings'] = SyncFormat::settingsRow($settings, Store::current());
        }

        return array_filter($master, fn ($rows) => $rows !== []);
    }

    /** Помечает принятое онлайн-сервером. Возвращает число принятых документов. */
    private function markDelivered(array $manifest, array $response): int
    {
        $now = now();
        $columns = ['sales' => [Sale::class, 'cloud_synced_at'], 'returns' => [Refund::class, 'cloud_synced_at'],
            'incomes' => [Income::class, 'cloud_synced_at'], 'audits' => [Audit::class, 'cloud_synced_at'], 'orders' => [Order::class, 'synced_at']];
        $accepted = 0;
        foreach ($columns as $type => [$model, $column]) {
            $uuids = array_values(array_filter((array) ($response['accepted'][$type] ?? []), 'is_string'));
            foreach (array_chunk($uuids, 500) as $chunk) {
                $model::whereIn('uuid', $chunk)->toBase()->update([$column => $now]);
            }
            $accepted += count($uuids);
        }
        // правки справочников доставлены (приняты или проиграли конфликт) — дальше их состояние определит pull
        foreach ($manifest['master'] as [$model, $uuid, $updatedAt]) {
            $query = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();
            $query->where('uuid', $uuid)->whereNull('synced_at')->where('updated_at', $updatedAt)->toBase()->update(['synced_at' => $now]);
        }

        return $accepted + count($manifest['master']) + (int) ($response['products'] ?? 0);
    }

    /** id → uuid для ссылок документов. */
    private function uuidMaps(Collection $sales, Collection $returns, Collection $incomes, Collection $audits, Collection $orders): array
    {
        $employeeIds = collect([$sales, $returns, $incomes, $audits])->flatten(1)->pluck('employee_id')
            ->merge($orders->pluck('waiter_id'))->merge($orders->pluck('closed_by'))->filter()->unique()->all();
        $productIds = collect([$sales, $returns, $incomes, $audits])->flatMap(fn ($docs) => $docs->flatMap(fn ($d) => $d->items->pluck('product_id')))->filter()->unique()->all();
        $saleIds = $returns->pluck('sale_id')->merge($orders->pluck('sale_id'))->filter()->unique()->all();
        $saleItemIds = $returns->flatMap(fn ($r) => $r->items->pluck('sale_item_id'))->filter()->unique()->all();

        return [
            'employees' => Employee::withoutGlobalScopes()->whereIn('id', $employeeIds)->pluck('uuid', 'id')->all(),
            'products' => Product::whereIn('id', $productIds)->pluck('uuid', 'id')->all(),
            'sales' => Sale::whereIn('id', $saleIds)->pluck('uuid', 'id')->all(),
            'sale_items' => SaleItem::whereIn('id', $saleItemIds)->pluck('uuid', 'id')->all(),
            'tables' => Table::withTrashed()->whereIn('id', $orders->pluck('table_id')->filter()->unique())->pluck('uuid', 'id')->all(),
            'dishes' => Dish::withTrashed()->whereIn('id', $orders->flatMap(fn ($o) => $o->items->pluck('dish_id'))->filter()->unique())->pluck('uuid', 'id')->all(),
        ];
    }

    /** Записям, созданным в обход моделей (старые данные, демо кассы), — свои uuid. */
    private function ensureUuids(): void
    {
        $storeId = Store::current()->id;
        foreach (['kassa_products', 'kassa_sales', 'kassa_sale_items', 'kassa_returns', 'kassa_incomes', 'kassa_audits', 'kassa_employees'] as $table) {
            DB::table($table)->where('store_id', $storeId)->whereNull('uuid')->orderBy('id')->select('id')
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
                    }
                });
        }
    }

    // ---------------------------------------------------------------- вниз

    /** Применяет справочники онлайн-сервера. Возвращает, сколько записей изменилось. */
    private function applyPull(array $data): array
    {
        if ((int) ($data['format'] ?? 0) !== SyncFormat::VERSION) {
            throw new SyncException('Онлайн-сервер прислал данные в неизвестном формате — обновите программу на обоих серверах до одной версии');
        }

        return SyncGate::apply(fn () => DB::transaction(function () use ($data) {
            $changed = ['settings' => 0, 'categories' => 0, 'dishes' => 0, 'tables' => 0, 'staff' => 0];

            $s = $data['settings'] ?? null;
            if (is_array($s) && SyncFormat::isUuid($s['uuid'] ?? null)) {
                $settings = Settings::current();
                $keep = $settings->synced_at === null && $settings->uuid === $s['uuid'] && (int) $settings->version === (int) ($s['version'] ?? 0);
                if (! $keep) {
                    $store = Store::current();
                    SyncFormat::fillSettings($settings, $store, $s);
                    $settings->uuid = $s['uuid'];
                    $settings->version = (int) ($s['version'] ?? 1);
                    $changed['settings'] = (int) ($settings->isDirty([...$settings->syncFields(), 'uuid']) || $store->isDirty(['name', 'address']));
                    $settings->synced_at = now();
                    $settings->save();
                    $store->save();
                }
            }

            foreach ((array) ($data['categories'] ?? []) as $r) {
                $changed['categories'] += $this->upsert(Category::class, $r, fn (Category $m) => SyncFormat::fillCategory($m, $r));
            }
            $categoryIds = Category::withTrashed()->pluck('id', 'uuid');
            foreach ((array) ($data['dishes'] ?? []) as $r) {
                $changed['dishes'] += $this->upsert(Dish::class, $r, fn (Dish $m) => SyncFormat::fillDish($m, $r, $categoryIds[$r['category_uuid'] ?? ''] ?? null));
            }
            foreach ((array) ($data['tables'] ?? []) as $r) {
                $changed['tables'] += $this->upsert(Table::class, $r, fn (Table $m) => SyncFormat::fillTable($m, $r));
            }
            foreach ((array) ($data['staff'] ?? []) as $r) {
                if (! in_array($r['role'] ?? null, SyncFormat::STAFF_ROLES, true) || ! is_string($r['pin'] ?? null)) {
                    continue;
                }
                $changed['staff'] += $this->upsert(Employee::class, $r, fn (Employee $m) => SyncFormat::fillStaff($m, $r));
            }
            // отключённые на онлайн-сервере сотрудники сразу теряют вход
            Token::whereIn('employee_id', Employee::whereNotNull('store_id')->where('active', false)->pluck('id'))->delete();

            return $changed;
        }));
    }

    /**
     * Онлайн-версия записи побеждает. Исключение — запись изменена здесь и ещё не отправлена, а на онлайн-сервере
     * с тех пор не менялась (версии совпадают): тогда сохраняется локальная правка — она уйдёт при следующем push.
     */
    private function upsert(string $model, array $r, Closure $fill): int
    {
        $uuid = $r['uuid'] ?? null;
        if (! SyncFormat::isUuid($uuid)) {
            return 0;
        }
        $soft = $model !== Employee::class;
        $m = ($soft ? $model::withTrashed() : $model::query())->where('uuid', $uuid)->first();
        $deleted = (bool) ($r['deleted'] ?? false);
        if (! $m && ($deleted || $model::withoutGlobalScopes()->where('uuid', $uuid)->exists())) {
            return 0;
        }
        if ($m && $m->synced_at === null && (int) $m->version === (int) ($r['version'] ?? 0)) {
            return 0;
        }
        $m ??= new $model;
        $m->uuid = $uuid;
        $fill($m);
        if ($soft) {
            SyncFormat::markDeleted($m, $deleted);
        }
        $m->version = (int) ($r['version'] ?? 1);
        $changed = ! $m->exists || $m->isDirty($m->syncFields());
        $m->synced_at = now();
        $m->save();

        return $changed ? 1 : 0;
    }
}
