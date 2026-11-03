<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant\Dish;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\PrintJob;
use App\Models\Restaurant\Settings;
use App\Services\Restaurant\Printing\PrintQueue;
use App\Support\Kassa\Clock;
use App\Support\Restaurant\Presenter;

/**
 * Доска кухни: карточки отправок (заказов и дозаказов) по колонкам «Новые → Готовятся → Готово»,
 * недавние отмены, стоп-лист и — при печати из браузера — чеки, которые экран кухни должен напечатать.
 */
class KitchenBoard
{
    /** Сколько минут показывать сообщение об отмене. */
    private const CANCEL_NOTICE_MINUTES = 10;

    public function __construct(private readonly PrintQueue $printer) {}

    public function build(): array
    {
        $settings = Settings::current();
        $ticketIds = OrderItem::whereIn('status', OrderItem::ON_BOARD)->whereNotNull('ticket_id')->distinct()->pluck('ticket_id');
        $cards = KitchenTicket::with(['order', 'employee', 'items'])->whereIn('id', $ticketIds)->get()
            ->filter(fn (KitchenTicket $t) => $t->order && $t->order->isOpen())
            ->map(fn (KitchenTicket $t) => Presenter::ticketCard($t, $t->items))
            ->filter(fn (array $c) => in_array($c['column'], ['new', 'cooking', 'ready'], true))
            ->sortBy([['sent_at', 'asc'], ['id', 'asc']])
            ->values()->all();

        $cancelled = OrderItem::with('order')->where('status', 'cancelled')->whereNotNull('sent_at')
            ->where('cancelled_at', '>=', now()->subMinutes(self::CANCEL_NOTICE_MINUTES))
            ->orderByDesc('cancelled_at')->limit(20)->get()
            ->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'number' => $i->order?->number,
                'table_name' => $i->order?->table_name,
                'name' => $i->name,
                'qty' => $i->qty,
                'at' => Clock::iso($i->cancelled_at),
            ])->values()->all();

        $browser = $settings->printer_mode === 'browser';
        $network = $settings->printer_mode === 'network';
        $lastProblem = $network ? PrintJob::whereIn('status', ['pending', 'failed'])->whereNotNull('last_error')->orderByDesc('id')->first() : null;

        // экран кухни опрашивает сервер каждые несколько секунд — заодно повторяем печать на сетевой принтер
        if ($network && $this->printer->hasDue()) {
            $this->printer->deliverAfterResponse();
        }

        return [
            'server_time' => Clock::now()->toIso8601String(),
            'cook_minutes' => $settings->cook_minutes,
            'printer_mode' => $settings->printer_mode,
            'cards' => $cards,
            'cancelled' => $cancelled,
            'stop_list' => Dish::where('stop', true)->where('active', true)->orderBy('name')->pluck('name')->all(),
            'print_jobs' => $browser
                ? PrintJob::where('status', 'pending')->orderBy('id')->limit(10)->get()->map(fn ($j) => Presenter::printJob($j, true))->all()
                : [],
            'printer_problem' => $lastProblem ? [
                'message' => $lastProblem->last_error,
                'waiting' => PrintJob::where('status', 'pending')->count(),
                'failed' => PrintJob::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            ] : null,
        ];
    }
}
