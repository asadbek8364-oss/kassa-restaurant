<?php

namespace App\Support\Restaurant;

use App\Models\Kassa\Employee;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\PrintJob;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\SyncLog;
use App\Models\Restaurant\Table;
use App\Models\Restaurant\TerminalPayment;
use App\Services\Restaurant\Terminal\EmulatorTerminal;
use App\Support\Kassa\Clock;
use Illuminate\Support\Collection;

/** JSON ресторанных приложений. Суммы — целые, время — ISO 8601 в часовом поясе ресторана. */
class Presenter
{
    public static function category(Category $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'sort' => $c->sort, 'unsynced' => self::unsynced($c)];
    }

    public static function dish(Dish $d): array
    {
        return [
            'id' => $d->id,
            'category_id' => $d->category_id,
            'name' => $d->name,
            'description' => $d->description,
            'price' => $d->price,
            'active' => $d->active,
            'stop' => $d->stop,
            'sort' => $d->sort,
            'unsynced' => self::unsynced($d),
        ];
    }

    public static function table(Table $t, ?Order $open = null): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'seats' => $t->seats,
            'active' => $t->active,
            'sort' => $t->sort,
            'status' => $open ? ($open->bill_at ? 'bill' : 'busy') : 'free',
            'order' => $open ? self::orderSummary($open) : null,
            'unsynced' => self::unsynced($t),
        ];
    }

    public static function staff(Employee $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'role' => $e->role,
            'role_name' => Apps::ROLE_NAMES[$e->role] ?? $e->role,
            'phone' => $e->phone,
            'active' => $e->active,
            'global' => $e->store_id === null,
            'unsynced' => $e->store_id !== null && self::unsynced($e),
        ];
    }

    public static function orderSummary(Order $o): array
    {
        $items = $o->relationLoaded('items') ? $o->items : null;

        return [
            'id' => $o->id,
            'number' => $o->number,
            'type' => $o->type,
            'place' => $o->place(),
            'table_id' => $o->table_id,
            'table_name' => $o->table_name,
            'waiter' => ['id' => $o->waiter_id, 'name' => $o->waiter_name],
            'guests' => $o->guests,
            'comment' => $o->comment,
            'status' => $o->status,
            'status_name' => Order::STATUS_NAMES[$o->status] ?? $o->status,
            'total' => $o->total,
            'items_count' => $items ? (int) $items->where('status', '!=', 'cancelled')->sum('qty') : null,
            'ready_count' => $items ? (int) $items->where('status', 'ready')->sum('qty') : null,
            'payment' => $o->payment,
            'paid' => $o->isPaid(),
            'created_at' => Clock::iso($o->created_at),
            'bill_at' => Clock::iso($o->bill_at),
            'paid_at' => Clock::iso($o->paid_at),
            'cancelled_at' => Clock::iso($o->cancelled_at),
        ];
    }

    /** Короткий состав заказа (без отменённого) — для очереди навынос у кассира. */
    public static function lines(Order $o): array
    {
        return $o->items->where('status', '!=', 'cancelled')->sortBy('id')
            ->map(fn (OrderItem $i) => ['name' => $i->name, 'qty' => $i->qty, 'status' => $i->status])->values()->all();
    }

    public static function order(Order $o): array
    {
        $o->loadMissing(['items', 'tickets.employee', 'sale', 'terminalPayments']);

        return self::orderSummary($o) + [
            'received' => $o->received,
            'change' => $o->change_amount,
            'cancel_reason' => $o->cancel_reason,
            'sale_number' => $o->sale?->number,
            'terminal' => $o->terminalPayments->sortByDesc('id')->map(fn (TerminalPayment $p) => self::terminalPayment($p))->values()->all(),
            'items' => $o->items->sortBy('id')->map(fn (OrderItem $i) => self::item($i))->values()->all(),
            'tickets' => $o->tickets->sortBy('batch')->map(fn (KitchenTicket $t) => [
                'id' => $t->id,
                'batch' => $t->batch,
                'comment' => $t->comment,
                'sent_at' => Clock::iso($t->sent_at),
                'employee' => $t->employee?->name,
            ])->values()->all(),
        ];
    }

    public static function item(OrderItem $i): array
    {
        return [
            'id' => $i->id,
            'dish_id' => $i->dish_id,
            'name' => $i->name,
            'price' => $i->price,
            'qty' => $i->qty,
            'sum' => $i->qty * $i->price,
            'comment' => $i->comment,
            'status' => $i->status,
            'batch' => $i->batch,
            'ticket_id' => $i->ticket_id,
            'sent_at' => Clock::iso($i->sent_at),
            'cooking_at' => Clock::iso($i->cooking_at),
            'ready_at' => Clock::iso($i->ready_at),
            'served_at' => Clock::iso($i->served_at),
        ];
    }

    /**
     * Карточка кухни: одна отправка на кухню (заказ или дозаказ). column — колонка доски: new, cooking, ready.
     *
     * @param  Collection<int, OrderItem>  $items
     */
    public static function ticketCard(KitchenTicket $t, Collection $items): array
    {
        $order = $t->order;
        $active = $items->where('status', '!=', 'cancelled');

        return [
            'id' => $t->id,
            'order_id' => $order->id,
            'number' => $order->number,
            'batch' => $t->batch,
            'takeaway' => $order->isTakeaway(),
            'place' => $order->place(),
            'table_name' => $order->table_name,
            'guests' => $order->guests,
            'waiter' => $t->employee?->name ?? $order->waiter_name,
            'comment' => $t->comment,
            'column' => OrderItem::aggregate($active),
            'sent_at' => Clock::iso($t->sent_at),
            'started_at' => Clock::iso($active->pluck('cooking_at')->filter()->min()),
            'ready_at' => $active->every(fn ($i) => in_array($i->status, ['ready', 'served'], true))
                ? Clock::iso($active->pluck('ready_at')->filter()->max()) : null,
            'items' => $items->sortBy('id')->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'qty' => $i->qty,
                'comment' => $i->comment,
                'status' => $i->status,
            ])->values()->all(),
        ];
    }

    public static function settings(Settings $s): array
    {
        return [
            'cook_minutes' => $s->cook_minutes,
            'receipt_header' => $s->receipt_header,
            'receipt_footer' => $s->receipt_footer,
            'printer_mode' => $s->printer_mode,
            'printer_host' => $s->printer_host,
            'printer_port' => $s->printer_port,
            'printer_codepage' => $s->printer_codepage,
            'printer_width' => $s->printer_width,
            'terminal_enabled' => (bool) $s->terminal_enabled,
            'terminal_host' => $s->terminal_host,
            'terminal_port' => $s->terminal_port,
            'terminal_test' => (bool) $s->terminal_test,
            'unsynced' => self::unsynced($s),
        ];
    }

    /** Оплата через терминал кассы: ожидание и результат для экрана кассира и админки. */
    public static function terminalPayment(TerminalPayment $p): array
    {
        return [
            'id' => $p->id,
            'order_id' => $p->order_id,
            'amount' => $p->amount,
            'status' => $p->status,
            'status_name' => TerminalPayment::STATUS_NAMES[$p->status] ?? $p->status,
            'message' => $p->message,
            'reference' => $p->reference,
            'auth_code' => $p->auth_code,
            'card' => $p->card,
            'test' => $p->driver === EmulatorTerminal::NAME,
            'created_at' => Clock::iso($p->created_at),
            'finished_at' => Clock::iso($p->finished_at),
        ];
    }

    public static function printJob(PrintJob $j, bool $withDocument = false): array
    {
        return [
            'id' => $j->id,
            'kind' => $j->kind,
            'kind_name' => PrintJob::KIND_NAMES[$j->kind] ?? $j->kind,
            'title' => $j->title,
            'status' => $j->status,
            'attempts' => $j->attempts,
            'last_error' => $j->last_error,
            'next_attempt_at' => Clock::iso($j->next_attempt_at),
            'printed_at' => Clock::iso($j->printed_at),
            'printed_via' => $j->printed_via,
            'created_at' => Clock::iso($j->created_at),
        ] + ($withDocument ? ['document' => $j->document] : []);
    }

    public static function syncLog(SyncLog $l): array
    {
        return [
            'id' => $l->id,
            'direction' => $l->direction,
            'trigger' => $l->trigger,
            'status' => $l->status,
            'sent' => $l->sent ?? [],
            'received' => $l->received ?? [],
            'error' => $l->error,
            'started_at' => Clock::iso($l->started_at),
            'finished_at' => Clock::iso($l->finished_at),
        ];
    }

    /**
     * На локальном сервере запись изменена и ещё не сверена с онлайн-сервером.
     * Админка показывает пометку, только если синхронизация настроена.
     */
    private static function unsynced(object $model): bool
    {
        return Mode::local() && $model->synced_at === null;
    }
}
