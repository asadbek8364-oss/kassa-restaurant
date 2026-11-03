<?php

namespace App\Services\Kassa;

use App\Models\Kassa\Product;
use App\Models\Kassa\SaleItem;
use App\Support\Kassa\Clock;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Прогнозы по истории продаж — обычная статистика на сервере, без внешних сервисов.
 *
 * Для каждого товара берётся ряд «продано штук за день» за последние HISTORY_DAYS полных дней
 * (сегодняшний неполный день не учитывается; для нового товара ряд начинается со дня его создания).
 *  - Скользящее среднее: avg = среднее ряда (окно 28 дней сдвигается каждый день), avg_7 — за последние 7 дней.
 *  - Тренд: наклон прямой по методу наименьших квадратов (шт/день за день). Учитывается, если в ряду
 *    не меньше MIN_TREND_DAYS дней, иначе тренд = 0.
 *  - Спрос в день = avg + наклон × (n + H) / 2 — значение линии тренда в середине горизонта H = HORIZON_DAYS;
 *    поправка на тренд ограничена ±TREND_CAP × avg, спрос не меньше нуля.
 *  - Хватит на N дней = остаток / спрос (вниз до целого); нет спроса — срок не считается.
 *  - К заказу = спрос × H + мин. остаток − остаток (вверх до целого): запас на две недели плюс страховой минимум.
 *  - «Мёртвый» товар: есть на складе, но не продавался DEAD_DAYS+ дней (или ни разу с момента создания);
 *    заморожено = остаток × закупочная цена.
 */
class KassaForecastService
{
    public const HISTORY_DAYS = 28;

    public const HORIZON_DAYS = 14;

    public const MIN_TREND_DAYS = 14;

    public const TREND_CAP = 0.5;

    /** Пороги «закончится меньше чем через N дней». */
    public const THRESHOLDS = [3, 7, 14];

    public const DEFAULT_THRESHOLD = 7;

    /** Сроки «без продаж N+ дней». */
    public const DEAD_DAYS = [30, 60, 90];

    public const DEFAULT_DEAD_DAYS = 30;

    /**
     * Прогноз по дневному ряду продаж (от старых дней к новым).
     *
     * @param  list<int|float>  $daily
     * @return array{days:int, avg_7:float, avg:float, slope:float, trend_pct:?int, rate:float}
     */
    public static function fromSeries(array $daily, int $horizon = self::HORIZON_DAYS): array
    {
        $daily = array_values($daily);
        $n = count($daily);
        if ($n === 0) {
            return ['days' => 0, 'avg_7' => 0.0, 'avg' => 0.0, 'slope' => 0.0, 'trend_pct' => null, 'rate' => 0.0];
        }

        $mean = array_sum($daily) / $n;
        $week = array_slice($daily, -7);
        $avg7 = array_sum($week) / count($week);

        $slope = 0.0;
        if ($n >= self::MIN_TREND_DAYS) {
            $tMean = ($n - 1) / 2;
            $num = $den = 0.0;
            foreach ($daily as $t => $y) {
                $num += ($t - $tMean) * ($y - $mean);
                $den += ($t - $tMean) ** 2;
            }
            $slope = $num / $den;
        }

        $cap = self::TREND_CAP * $mean;
        $shift = max(-$cap, min($cap, $slope * ($n + $horizon) / 2));

        return [
            'days' => $n,
            'avg_7' => $avg7,
            'avg' => $mean,
            'slope' => $slope,
            'trend_pct' => $n >= self::MIN_TREND_DAYS && $mean > 0 ? (int) round($slope * 7 / $mean * 100) : null,
            'rate' => max(0.0, $mean + $shift),
        ];
    }

    /** Прогноз одного товара (карточка товара). */
    public function product(Product $p): array
    {
        return $this->forecast($p, $this->seriesFor($p, $this->buckets($p->id)[$p->id] ?? null));
    }

    /**
     * Раздел «Прогнозы»: что закончится раньше порога и сколько заказать; что не продаётся и сколько в нём денег.
     */
    public function report(int $threshold = self::DEFAULT_THRESHOLD, int $deadDays = self::DEFAULT_DEAD_DAYS): array
    {
        $today = Clock::today();
        $buckets = $this->buckets();
        $lastSales = $this->lastSales();
        $running = $dead = [];

        foreach (Product::orderBy('name')->get() as $p) {
            $f = $this->forecast($p, $this->seriesFor($p, $buckets[$p->id] ?? null));
            if ($f['days_left'] !== null && $f['days_left'] < $threshold) {
                $running[] = $f;
            }

            if ($p->stock > 0) {
                $last = isset($lastSales[$p->id]) ? Clock::local($lastSales[$p->id]) : null;
                $idle = $this->daysBetween(($last ?? Clock::local($p->created_at))->startOfDay(), $today);
                if ($idle >= $deadDays) {
                    $dead[] = [
                        'product_id' => $p->id,
                        'name' => $p->name,
                        'category' => $p->category,
                        'stock' => $p->stock,
                        'buy_price' => $p->buy_price,
                        'last_sale_at' => $last?->toIso8601String(),
                        'idle_days' => $idle,
                        'frozen' => $p->stock * $p->buy_price,
                    ];
                }
            }
        }

        usort($running, fn ($a, $b) => [$a['days_left'], -$a['per_day']] <=> [$b['days_left'], -$b['per_day']]);
        usort($dead, fn ($a, $b) => [-$a['frozen'], $a['name']] <=> [-$b['frozen'], $b['name']]);

        return [
            'threshold' => $threshold,
            'dead_days' => $deadDays,
            'history_days' => self::HISTORY_DAYS,
            'horizon_days' => self::HORIZON_DAYS,
            'date' => $today->toDateString(),
            'running_out' => $running,
            'dead' => $dead,
            'totals' => [
                'running_out' => count($running),
                'order_units' => array_sum(array_column($running, 'order_qty')),
                'order_sum' => array_sum(array_column($running, 'order_sum')),
                'dead' => count($dead),
                'dead_units' => array_sum(array_column($dead, 'stock')),
                'dead_frozen' => array_sum(array_column($dead, 'frozen')),
            ],
        ];
    }

    /** Короткая сводка для главного экрана и сводки по магазинам. */
    public function overview(int $threshold = self::DEFAULT_THRESHOLD, int $deadDays = self::DEFAULT_DEAD_DAYS): array
    {
        $r = $this->report($threshold, $deadDays);

        return [
            'threshold' => $threshold,
            'dead_days' => $deadDays,
            'running_out_count' => $r['totals']['running_out'],
            'running_out' => array_map(
                fn ($f) => array_intersect_key($f, array_flip(['product_id', 'name', 'stock', 'per_day', 'days_left', 'order_qty'])),
                array_slice($r['running_out'], 0, 3),
            ),
            'order_sum' => $r['totals']['order_sum'],
            'dead_count' => $r['totals']['dead'],
            'dead_frozen' => $r['totals']['dead_frozen'],
        ];
    }

    private function forecast(Product $p, array $series): array
    {
        $f = self::fromSeries($series);
        $rate = $f['rate'];
        $daysLeft = $rate > 0 ? (int) floor($p->stock / $rate) : null;
        $need = $rate > 0 ? $rate * self::HORIZON_DAYS + $p->min_stock - $p->stock : 0;
        $order = $need > 0 ? (int) ceil($need - 1e-9) : 0;

        return [
            'product_id' => $p->id,
            'name' => $p->name,
            'category' => $p->category,
            'stock' => $p->stock,
            'min_stock' => $p->min_stock,
            'buy_price' => $p->buy_price,
            'history_days' => $f['days'],
            'avg_7' => round($f['avg_7'], 2),
            'avg_28' => round($f['avg'], 2),
            'trend_pct' => $f['trend_pct'],
            'per_day' => round($rate, 2),
            'forecast_qty' => (int) round($rate * self::HORIZON_DAYS),
            'horizon_days' => self::HORIZON_DAYS,
            'days_left' => $daysLeft,
            'runs_out_on' => $daysLeft !== null ? Clock::today()->addDays($daysLeft)->toDateString() : null,
            'order_qty' => $order,
            'order_sum' => $order * $p->buy_price,
        ];
    }

    /** Ряд товара: дни окна начиная с дня создания товара. */
    private function seriesFor(Product $p, ?array $bucket): array
    {
        $from = Clock::today()->subDays(self::HISTORY_DAYS);
        $start = max(0, $this->daysBetween($from, Clock::local($p->created_at)->startOfDay()));

        return array_slice($bucket ?? array_fill(0, self::HISTORY_DAYS, 0), $start);
    }

    /**
     * Продажи по дням окна для всех товаров магазина (или одного): [product_id => [день 0 … день 27]].
     * Дни считаются в часовом поясе магазина, поэтому группировка — здесь, а не в SQL.
     *
     * @return array<int, list<int>>
     */
    private function buckets(?int $productId = null): array
    {
        $today = Clock::today();
        $from = $today->subDays(self::HISTORY_DAYS);
        $rows = SaleItem::join('kassa_sales', 'kassa_sales.id', '=', 'kassa_sale_items.sale_id')
            ->where('kassa_sales.created_at', '>=', Clock::toDb($from))
            ->where('kassa_sales.created_at', '<', Clock::toDb($today))
            ->whereNotNull('kassa_sale_items.product_id')
            ->when($productId, fn ($q) => $q->where('kassa_sale_items.product_id', $productId))
            ->toBase()
            ->select(['kassa_sale_items.product_id as pid', 'kassa_sales.created_at as at', 'kassa_sale_items.qty as qty'])
            ->cursor();

        $out = [];
        foreach ($rows as $r) {
            $day = $this->daysBetween($from, Clock::local($r->at)->startOfDay());
            if ($day < 0 || $day >= self::HISTORY_DAYS) {
                continue;
            }
            $out[(int) $r->pid] ??= array_fill(0, self::HISTORY_DAYS, 0);
            $out[(int) $r->pid][$day] += (int) $r->qty;
        }

        return $out;
    }

    /** Время последней продажи каждого товара (за всё время). */
    private function lastSales(): array
    {
        return SaleItem::join('kassa_sales', 'kassa_sales.id', '=', 'kassa_sale_items.sale_id')
            ->whereNotNull('kassa_sale_items.product_id')
            ->groupBy('kassa_sale_items.product_id')
            ->toBase()
            ->selectRaw('kassa_sale_items.product_id as pid, MAX(kassa_sales.created_at) as last_at')
            ->pluck('last_at', 'pid')
            ->all();
    }

    /** Целых дней между двумя началами суток (по календарю магазина). */
    private function daysBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        return (int) round(CarbonImmutable::instance($from)->diffInDays($to, false));
    }
}
