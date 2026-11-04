<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Support\Kassa\Clock;
use Carbon\CarbonImmutable;

/**
 * Отчёты ресторана по оплаченным заказам (дата оплаты, часовой пояс ресторана):
 * выручка по дням, по сотрудникам (официанты и кассиры), по столикам и навынос, средний чек, популярные блюда.
 * Оплаченный заранее заказ навынос считается в день оплаты, даже если его ещё не выдали.
 * Средний чек = выручка / число оплаченных заказов. На онлайн-сервере считается по заказам,
 * пришедшим с локального сервера.
 */
class RestaurantReportService
{
    public const MAX_DAYS = 366;

    /** Последние 7 дней, включая сегодня. */
    public function defaultRange(): array
    {
        $today = Clock::today();

        return [$today->subDays(6), $today];
    }

    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $range = [Clock::toDb($from), Clock::toDb($to->addDay())];

        $orders = Order::where('status', '!=', 'cancelled')->where('paid_at', '>=', $range[0])->where('paid_at', '<', $range[1])
            ->get(['id', 'type', 'total', 'guests', 'waiter_id', 'waiter_name', 'table_id', 'table_name', 'payment', 'paid_at']);
        $revenue = (int) $orders->sum('total');
        $count = $orders->count();

        $days = [];
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $days[$d->format('Y-m-d')] = ['day' => $d->format('Y-m-d'), 'revenue' => 0, 'orders' => 0];
        }
        foreach ($orders as $o) {
            $key = Clock::day($o->paid_at);
            if (isset($days[$key])) {
                $days[$key]['revenue'] += $o->total;
                $days[$key]['orders']++;
            }
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'revenue' => $revenue,
                'orders' => $count,
                'avg' => $count ? (int) round($revenue / $count) : 0,
                'guests' => (int) $orders->sum('guests'),
                'cancelled' => Order::where('status', 'cancelled')->where('cancelled_at', '>=', $range[0])->where('cancelled_at', '<', $range[1])->count(),
            ],
            'by_day' => array_values(array_map(fn ($d) => $d + ['avg' => $d['orders'] ? (int) round($d['revenue'] / $d['orders']) : 0], $days)),
            'by_waiter' => $this->group($orders, fn ($o) => $o->waiter_id ?? 'none:'.$o->waiter_name, 'waiter_id', fn ($o) => $o->waiter_name ?: 'Без официанта'),
            'by_table' => $this->group($orders, fn ($o) => $o->type === 'takeaway' ? 'takeaway' : ($o->table_id ?? 'none:'.$o->table_name), 'table_id',
                fn ($o) => $o->type === 'takeaway' ? 'Навынос' : ($o->table_name ?: 'Без столика')),
            'by_payment' => collect(Order::PAYMENTS)->map(fn ($p) => [
                'payment' => $p,
                'revenue' => (int) $orders->where('payment', $p)->sum('total'),
                'orders' => $orders->where('payment', $p)->count(),
            ])->all(),
            'top_dishes' => $this->topDishes($range),
        ];
    }

    /** Сводка за сегодня для главного экрана. */
    public function today(): array
    {
        $today = Clock::today();

        return $this->report($today, $today)['summary'];
    }

    private function group($orders, callable $key, string $idKey, callable $name): array
    {
        return $orders->groupBy($key)
            ->map(function ($group) use ($idKey, $name) {
                $revenue = (int) $group->sum('total');

                return [
                    'id' => $group->first()->{$idKey},
                    'name' => $name($group->sortByDesc('paid_at')->first()),
                    'revenue' => $revenue,
                    'orders' => $group->count(),
                    'avg' => (int) round($revenue / $group->count()),
                ];
            })
            ->sortByDesc('revenue')->values()->all();
    }

    private function topDishes(array $range, int $limit = 10): array
    {
        return OrderItem::join('restaurant_orders', 'restaurant_orders.id', '=', 'restaurant_order_items.order_id')
            ->where('restaurant_orders.status', '!=', 'cancelled')
            ->where('restaurant_orders.paid_at', '>=', $range[0])->where('restaurant_orders.paid_at', '<', $range[1])
            ->where('restaurant_order_items.status', '!=', 'cancelled')
            ->groupBy('restaurant_order_items.dish_id', 'restaurant_order_items.name')
            ->selectRaw('restaurant_order_items.dish_id as dish_id, restaurant_order_items.name as name, SUM(restaurant_order_items.qty) as qty, SUM(restaurant_order_items.qty * restaurant_order_items.price) as revenue')
            ->orderByDesc('qty')->orderByDesc('revenue')->limit($limit)
            ->toBase()->get()
            ->map(fn ($r) => ['dish_id' => $r->dish_id ? (int) $r->dish_id : null, 'name' => $r->name, 'qty' => (int) $r->qty, 'revenue' => (int) $r->revenue])
            ->all();
    }
}
