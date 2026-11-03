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
use App\Support\Kassa\Clock;
use App\Support\Kassa\Presenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Отчёты и сводки считаются на сервере.
 * Выручка = продажи − возвраты (по дате возврата). Средний чек = продажи / число чеков.
 * Валовая прибыль = выручка − себестоимость (закупочная цена на момент продажи).
 */
class KassaReportService
{
    public const PERIODS = ['today', 'week', 'month', 'all'];

    public function periodStart(string $period): CarbonImmutable
    {
        $today = Clock::today();

        return match ($period) {
            'today' => $today,
            'week' => $today->subDays(6),
            'month' => $today->subDays(29),
            default => $this->firstOperationDay() ?? $today,
        };
    }

    private function firstOperationDay(): ?CarbonImmutable
    {
        $first = collect([Sale::min('created_at'), Refund::min('created_at')])->filter()->min();

        return $first ? Clock::local($first)->startOfDay() : null;
    }

    public function summary(CarbonImmutable $from): array
    {
        $f = Clock::toDb($from);
        $sales = Sale::where('created_at', '>=', $f)->toBase()
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total), 0) as t, COALESCE(SUM(cost_total), 0) as k')->first();
        $refunds = Refund::where('created_at', '>=', $f)->toBase()
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total), 0) as t, COALESCE(SUM(cost_total), 0) as k')->first();
        $soldUnits = (int) SaleItem::join('kassa_sales', 'kassa_sales.id', '=', 'kassa_sale_items.sale_id')
            ->where('kassa_sales.created_at', '>=', $f)->sum('kassa_sale_items.qty');
        $returnedUnits = (int) RefundItem::join('kassa_returns', 'kassa_returns.id', '=', 'kassa_return_items.return_id')
            ->where('kassa_returns.created_at', '>=', $f)->sum('kassa_return_items.qty');

        $gross = (int) $sales->t;
        $refundSum = (int) $refunds->t;
        $checks = (int) $sales->c;
        $revenue = $gross - $refundSum;
        $profit = $revenue - ((int) $sales->k - (int) $refunds->k);

        return [
            'revenue' => $revenue,
            'gross' => $gross,
            'refunds' => $refundSum,
            'refunds_count' => (int) $refunds->c,
            'checks' => $checks,
            'units' => $soldUnits - $returnedUnits,
            'avg' => $checks ? (int) round($gross / $checks) : 0,
            'profit' => $profit,
            'margin_pct' => $revenue > 0 ? (int) round($profit / $revenue * 100) : null,
        ];
    }

    public function byPayment(CarbonImmutable $from, int $revenue): array
    {
        $f = Clock::toDb($from);
        $sales = Sale::where('created_at', '>=', $f)->groupBy('payment')->selectRaw('payment, SUM(total) as s')->pluck('s', 'payment');
        $refunds = Refund::where('created_at', '>=', $f)->groupBy('payment')->selectRaw('payment, SUM(total) as s')->pluck('s', 'payment');

        return collect(Sale::PAYMENTS)->map(function ($p) use ($sales, $refunds, $revenue) {
            $value = (int) ($sales[$p] ?? 0) - (int) ($refunds[$p] ?? 0);

            return ['payment' => $p, 'value' => $value, 'share_pct' => $revenue > 0 ? (int) round($value / $revenue * 100) : 0];
        })->all();
    }

    /** Продажи по продавцам; возврат вычитается у того, кто продал. */
    public function bySeller(CarbonImmutable $from): array
    {
        $f = Clock::toDb($from);
        $rows = [];
        Sale::where('created_at', '>=', $f)->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as c, SUM(total) as s')->toBase()->get()
            ->each(function ($r) use (&$rows) {
                $rows[(int) $r->employee_id] = ['checks' => (int) $r->c, 'sum' => (int) $r->s];
            });
        Refund::join('kassa_sales', 'kassa_sales.id', '=', 'kassa_returns.sale_id')
            ->where('kassa_returns.created_at', '>=', $f)->groupBy('kassa_sales.employee_id')
            ->selectRaw('kassa_sales.employee_id as employee_id, SUM(kassa_returns.total) as s')->toBase()->get()
            ->each(function ($r) use (&$rows) {
                $rows[(int) $r->employee_id] ??= ['checks' => 0, 'sum' => 0];
                $rows[(int) $r->employee_id]['sum'] -= (int) $r->s;
            });

        return $rows;
    }

    public function topProducts(CarbonImmutable $from, int $limit = 10): array
    {
        $f = Clock::toDb($from);
        $rows = [];
        $add = function ($r, int $sign) use (&$rows) {
            $key = ($r->product_id ?? 'x').'|'.$r->name;
            $rows[$key] ??= ['product_id' => $r->product_id ? (int) $r->product_id : null, 'name' => $r->name, 'qty' => 0, 'value' => 0];
            $rows[$key]['qty'] += $sign * (int) $r->q;
            $rows[$key]['value'] += $sign * (int) $r->s;
        };
        SaleItem::join('kassa_sales', 'kassa_sales.id', '=', 'kassa_sale_items.sale_id')
            ->where('kassa_sales.created_at', '>=', $f)
            ->groupBy('kassa_sale_items.product_id', 'kassa_sale_items.name')
            ->selectRaw('kassa_sale_items.product_id as product_id, kassa_sale_items.name as name, SUM(kassa_sale_items.qty) as q, SUM(kassa_sale_items.qty * kassa_sale_items.price) as s')
            ->toBase()->get()->each(fn ($r) => $add($r, 1));
        RefundItem::join('kassa_returns', 'kassa_returns.id', '=', 'kassa_return_items.return_id')
            ->where('kassa_returns.created_at', '>=', $f)
            ->groupBy('kassa_return_items.product_id', 'kassa_return_items.name')
            ->selectRaw('kassa_return_items.product_id as product_id, kassa_return_items.name as name, SUM(kassa_return_items.qty) as q, SUM(kassa_return_items.qty * kassa_return_items.price) as s')
            ->toBase()->get()->each(fn ($r) => $add($r, -1));

        return collect($rows)->filter(fn ($r) => $r['qty'] > 0)->sortByDesc('value')->take($limit)->values()->all();
    }

    /** Выручка по часам (сегодня), дням (до 62 дней) или месяцам. */
    public function series(string $period, CarbonImmutable $from): array
    {
        $now = Clock::now();
        $f = Clock::toDb($from);
        $unit = $period === 'today' ? 'hour' : ($from->diffInDays($now) < 62 ? 'day' : 'month');
        $format = ['hour' => 'H', 'day' => 'Y-m-d', 'month' => 'Y-m'][$unit];

        $sums = [];
        foreach (Sale::where('created_at', '>=', $f)->toBase()->cursor(['created_at', 'total']) as $r) {
            $k = Clock::local($r->created_at)->format($format);
            $sums[$k] = ($sums[$k] ?? 0) + (int) $r->total;
        }
        foreach (Refund::where('created_at', '>=', $f)->toBase()->cursor(['created_at', 'total']) as $r) {
            $k = Clock::local($r->created_at)->format($format);
            $sums[$k] = ($sums[$k] ?? 0) - (int) $r->total;
        }

        $points = [];
        if ($unit === 'hour') {
            $hours = array_map('intval', array_keys($sums));
            $a = min(9, ...($hours ?: [9]));
            $b = max(20, (int) $now->format('H'), ...($hours ?: [20]));
            for ($h = $a; $h <= $b; $h++) {
                $k = str_pad((string) $h, 2, '0', STR_PAD_LEFT);
                $points[] = ['key' => $from->setTime($h, 0)->toIso8601String(), 'value' => $sums[$k] ?? 0];
            }
        } elseif ($unit === 'day') {
            for ($d = $from; $d <= $now; $d = $d->addDay()) {
                $points[] = ['key' => $d->format('Y-m-d'), 'value' => $sums[$d->format('Y-m-d')] ?? 0];
            }
        } else {
            for ($m = $from->startOfMonth(); $m <= $now; $m = $m->addMonth()) {
                $points[] = ['key' => $m->format('Y-m'), 'value' => $sums[$m->format('Y-m')] ?? 0];
            }
        }

        return ['unit' => $unit, 'points' => $points];
    }

    public function lowStock(): array
    {
        return Product::whereColumn('stock', '<=', 'min_stock')->orderBy('stock')->orderBy('name')->get()
            ->map(fn ($p) => Presenter::product($p))->all();
    }

    public function warehouse(): array
    {
        $r = Product::toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(stock), 0) as units, COALESCE(SUM(stock * buy_price), 0) as cost, COALESCE(SUM(stock * sell_price), 0) as retail')->first();

        return [
            'positions' => (int) $r->n,
            'units' => (int) $r->units,
            'cost' => (int) $r->cost,
            'retail' => (int) $r->retail,
            'markup' => (int) $r->retail - (int) $r->cost,
        ];
    }

    public function report(string $period): array
    {
        $from = $this->periodStart($period);
        $summary = $this->summary($from);
        $names = Employee::pluck('name', 'id');

        return [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => Clock::today()->toDateString(),
            'summary' => $summary,
            'series' => $this->series($period, $from),
            'by_payment' => $this->byPayment($from, $summary['revenue']),
            'by_seller' => collect($this->bySeller($from))
                ->map(fn ($r, $id) => ['employee_id' => $id ?: null, 'name' => $names[$id] ?? 'Сотрудник удалён', 'checks' => $r['checks'], 'value' => $r['sum']])
                ->sortByDesc('value')->values()->all(),
            'top' => $this->topProducts($from),
            'low_stock' => $this->lowStock(),
            'warehouse' => $this->warehouse(),
        ];
    }

    public function dashboard(): array
    {
        return [
            'today' => $this->summary(Clock::today()),
            'low_stock' => $this->lowStock(),
            'recent_sales' => $this->recentSales(8),
        ];
    }

    public function recentSales(int $limit, ?int $employeeId = null): array
    {
        return Sale::with('employee')->withCount('refunds')->withSum('items as units', 'qty')->withSum('items as shortage_units', 'shortage')
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($s) => Presenter::saleSummary($s))->all();
    }

    /** Популярные за 30 дней товары в наличии, дополненные остальными по алфавиту. */
    public function popular(int $limit = 12): array
    {
        $ids = SaleItem::join('kassa_sales', 'kassa_sales.id', '=', 'kassa_sale_items.sale_id')
            ->join('kassa_products', 'kassa_products.id', '=', 'kassa_sale_items.product_id')
            ->where('kassa_sales.created_at', '>=', Clock::toDb(Clock::today()->subDays(29)))
            ->where('kassa_products.stock', '>', 0)
            ->groupBy('kassa_sale_items.product_id')
            ->orderByRaw('SUM(kassa_sale_items.qty) DESC')
            ->limit($limit)
            ->pluck('kassa_sale_items.product_id')->map(fn ($id) => (int) $id)->all();
        if (count($ids) < $limit) {
            $ids = array_merge($ids, Product::where('stock', '>', 0)->whereNotIn('id', $ids)->orderBy('name')->limit($limit - count($ids))->pluck('id')->all());
        }

        return $ids;
    }

    public function productDetail(Product $p): array
    {
        $f = Clock::toDb(Clock::today()->subDays(29));
        $sold = SaleItem::join('kassa_sales', 'kassa_sales.id', '=', 'kassa_sale_items.sale_id')
            ->where('kassa_sale_items.product_id', $p->id)->where('kassa_sales.created_at', '>=', $f)
            ->toBase()->selectRaw('COALESCE(SUM(kassa_sale_items.qty), 0) as q, COALESCE(SUM(kassa_sale_items.qty * kassa_sale_items.price), 0) as s')->first();

        return [
            'product' => Presenter::product($p),
            'sold_30d' => ['qty' => (int) $sold->q, 'sum' => (int) $sold->s],
            'moves' => $this->productMoves($p->id, 8),
        ];
    }

    /** Движение товара: продажи (−), возвраты и приходы (+), расхождения инвентаризаций. */
    public function productMoves(int $productId, int $limit): array
    {
        $names = Employee::pluck('name', 'id');
        $moves = collect();
        $pick = function (Builder $q, string $docTable, string $itemTable, string $fk, string $type, string $qtySql) use ($productId, $limit, $names, &$moves) {
            $q->join($docTable, "$docTable.id", '=', "$itemTable.$fk")
                ->where("$itemTable.product_id", $productId)
                ->when($type === 'aud', fn ($q) => $q->whereColumn("$itemTable.actual", '!=', "$itemTable.expected"))
                ->orderByDesc("$docTable.created_at")->limit($limit)
                ->selectRaw("$docTable.id as id, $docTable.number as number, $docTable.created_at as created_at, $docTable.employee_id as employee_id, $qtySql as qty")
                ->toBase()->get()
                ->each(function ($r) use ($type, $names, &$moves) {
                    $moves->push([
                        'type' => $type,
                        'id' => (int) $r->id,
                        'number' => (int) $r->number,
                        'qty' => (int) $r->qty,
                        'employee' => $r->employee_id ? ($names[$r->employee_id] ?? null) : null,
                        'created_at' => Clock::iso($r->created_at),
                        'ts' => Clock::local($r->created_at)->getTimestamp(),
                    ]);
                });
        };
        $pick(SaleItem::query(), 'kassa_sales', 'kassa_sale_items', 'sale_id', 'sale', '-kassa_sale_items.qty');
        $pick(RefundItem::query(), 'kassa_returns', 'kassa_return_items', 'return_id', 'ret', 'kassa_return_items.qty');
        $pick(IncomeItem::query(), 'kassa_incomes', 'kassa_income_items', 'income_id', 'inc', 'kassa_income_items.qty');
        $pick(AuditItem::query(), 'kassa_audits', 'kassa_audit_items', 'audit_id', 'aud', '(kassa_audit_items.actual - kassa_audit_items.expected)');

        return $moves->sortByDesc('ts')->take($limit)->map(fn ($m) => collect($m)->except('ts')->all())->values()->all();
    }

    /** Статистика сотрудников за сегодня и 30 дней. */
    public function employees(): array
    {
        $today = $this->bySeller(Clock::today());
        $month = $this->bySeller(Clock::today()->subDays(29));
        $zero = ['checks' => 0, 'sum' => 0];

        return Employee::orderByDesc('active')->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('name')->get()
            ->map(fn ($e) => Presenter::employee($e) + ['today' => $today[$e->id] ?? $zero, 'month' => $month[$e->id] ?? $zero])
            ->all();
    }

    public function employeeDetail(Employee $e): array
    {
        $zero = ['checks' => 0, 'sum' => 0];

        return [
            'employee' => Presenter::employee($e),
            'stats' => [
                'today' => $this->bySeller(Clock::today())[$e->id] ?? $zero,
                'week' => $this->bySeller(Clock::today()->subDays(6))[$e->id] ?? $zero,
                'month' => $this->bySeller(Clock::today()->subDays(29))[$e->id] ?? $zero,
            ],
            'recent_sales' => $this->recentSales(10, $e->id),
        ];
    }

    /**
     * Журнал операций: продажи, возвраты, приходы, инвентаризации — по убыванию времени,
     * с поиском и итогом выручки за каждый показанный день.
     */
    public function journal(string $type, string $q, int $offset, int $limit): array
    {
        $q = trim(ltrim(trim($q), '№'));
        $queries = [
            'sale' => fn () => $this->searchSales(Sale::query(), $q),
            'ret' => fn () => $this->searchDocs(Refund::query(), $q, ['reason'], fn ($b) => $b->orWhereHas('sale', fn ($s) => $s->where('number', $q))),
            'inc' => fn () => $this->searchDocs(Income::query(), $q, ['supplier']),
            'aud' => fn () => $this->searchDocs(Audit::query(), $q, []),
        ];
        $counts = [];
        foreach ($queries as $k => $make) {
            $counts[$k] = $make()->count();
        }
        $counts['all'] = array_sum($counts);

        $selected = $type === 'all' ? array_keys($queries) : [$type];
        $entries = collect();
        foreach ($selected as $k) {
            $docs = $queries[$k]()->with('employee')
                ->when($k === 'sale', fn ($b) => $b->withSum('items as shortage_units', 'shortage'))
                ->when($k === 'ret', fn ($b) => $b->with('sale'))
                ->when($k === 'inc', fn ($b) => $b->withSum('items as units', 'qty'))
                ->when($k === 'aud', fn ($b) => $b->with('items'))
                ->orderByDesc('created_at')->orderByDesc('id')->limit($offset + $limit)->get();
            foreach ($docs as $d) {
                $entries->push($this->journalEntry($k, $d));
            }
        }
        $page = $entries->sortByDesc(fn ($e) => [$e['ts'], $e['id']])->slice($offset, $limit)->values();

        $days = [];
        foreach ($page->pluck('day')->unique() as $day) {
            $start = CarbonImmutable::parse($day, Clock::tz());
            $range = [Clock::toDb($start), Clock::toDb($start->addDay())];
            $days[$day] = [
                'net' => (int) Sale::where('created_at', '>=', $range[0])->where('created_at', '<', $range[1])->sum('total')
                    - (int) Refund::where('created_at', '>=', $range[0])->where('created_at', '<', $range[1])->sum('total'),
            ];
        }

        return [
            'entries' => $page->map(fn ($e) => collect($e)->except('ts')->all())->all(),
            'counts' => $counts,
            'total' => $type === 'all' ? $counts['all'] : $counts[$type],
            'days' => $days,
        ];
    }

    private function searchSales(Builder $b, string $q): Builder
    {
        if ($q === '') {
            return $b;
        }

        return $b->where(function ($w) use ($q) {
            if (ctype_digit($q)) {
                $w->orWhere('number', (int) $q);
            }
            $w->orWhere('customer', 'like', "%{$q}%")
                ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$q}%"))
                ->orWhereHas('items', fn ($i) => $i->where('name', 'like', "%{$q}%"));
        });
    }

    private function searchDocs(Builder $b, string $q, array $textColumns, ?callable $extra = null): Builder
    {
        if ($q === '') {
            return $b;
        }

        return $b->where(function ($w) use ($q, $textColumns, $extra) {
            if (ctype_digit($q)) {
                $w->orWhere('number', (int) $q);
                if ($extra) {
                    $extra($w);
                }
            }
            foreach ($textColumns as $col) {
                $w->orWhere($col, 'like', "%{$q}%");
            }
            $w->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$q}%"))
                ->orWhereHas('items', fn ($i) => $i->where('name', 'like', "%{$q}%"));
        });
    }

    private function journalEntry(string $type, $d): array
    {
        $base = [
            'type' => $type,
            'id' => $d->id,
            'number' => $d->number,
            'created_at' => Clock::iso($d->created_at),
            'day' => Clock::day($d->created_at),
            'ts' => Clock::local($d->created_at)->getTimestamp(),
            'employee' => $d->employee?->name,
        ];

        return $base + match ($type) {
            'sale' => ['payment' => $d->payment, 'customer' => $d->customer, 'total' => $d->total, 'offline' => $d->isOffline(), 'conflict' => Presenter::saleConflict($d)],
            'ret' => ['sale_number' => $d->sale?->number, 'reason' => $d->reason, 'total' => $d->total],
            'inc' => ['supplier' => $d->supplier, 'total' => $d->total, 'units' => (int) $d->units],
            'aud' => collect(Presenter::audit($d, false))->only(['checked', 'shortage_qty', 'surplus_qty'])->all(),
        };
    }
}
