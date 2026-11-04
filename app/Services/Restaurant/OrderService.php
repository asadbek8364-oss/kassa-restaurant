<?php

namespace App\Services\Restaurant;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Sale;
use App\Models\Kassa\SaleItem;
use App\Models\Kassa\Store;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\Table;
use App\Models\Restaurant\TerminalPayment;
use App\Services\Restaurant\Printing\PrintQueue;
use App\Services\Restaurant\Printing\ReceiptTemplates;
use App\Support\Restaurant\Mode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Жизненный цикл заказа: открыть на столик (или навынос) и отправить на кухню → дозаказ → готовится → готово →
 * подано (навынос — выдано) → счёт → оплата (заказ попадает в продажи кассы) или отмена.
 *
 * Экран кухни только показывает заказы: отметки «готовится», «готово», «подано/выдано» ставят официант, кассир
 * и администратор (setStatus, serve, handOut).
 *
 * Навынос можно оплатить заранее, пока блюда готовятся: продажа создаётся сразу, заказ закрывается, когда его
 * выдали гостю. Оплаченный заказ не меняется: дозаказ и отмена закрыты (возврат денег — в «Кассе и складе»).
 * Пока оплата ждёт банковский терминал, заказ нельзя изменить, отменить или оплатить иначе.
 *
 * Каждая операция — одна транзакция; первая запись — строка ресторана (Store::locked), поэтому параллельные
 * официанты, кассир и админка меняют данные строго по очереди. Цены — только из меню на сервере.
 * Отправка на кухню идемпотентна по client_uuid: повтор запроса с устройства не создаёт второй чек.
 */
class OrderService
{
    public function __construct(
        private readonly PrintQueue $printer,
        private readonly ReceiptTemplates $templates,
    ) {}

    /**
     * Открыть заказ на столик и сразу отправить его на кухню.
     *
     * @param  list<array{dish_id:int, qty:int, comment?:?string}>  $lines
     * @return array{0: Order, 1: bool} [заказ, это повтор]
     */
    public function open(Employee $by, Table $table, array $lines, ?string $comment, ?int $guests, ?string $clientUuid): array
    {
        return $this->create($by, $lines, $comment, $clientUuid, function () use ($table, $guests) {
            $table = Table::find($table->id);
            if (! $table || ! $table->active) {
                $this->fail('table_id', 'Столик недоступен — обновите схему зала');
            }
            if ($open = $table->openOrder()) {
                throw new RestaurantConflict("На столике {$table->name} уже открыт заказ №{$open->number} — добавьте блюда в него", $open->id);
            }

            return ['type' => 'table', 'table_id' => $table->id, 'table_name' => $table->name, 'guests' => $guests ?: null];
        });
    }

    /**
     * Заказ навынос (кассир): без столика, сразу на кухню. Комментарий — например, имя гостя.
     *
     * @param  list<array{dish_id:int, qty:int, comment?:?string}>  $lines
     * @return array{0: Order, 1: bool}
     */
    public function openTakeaway(Employee $by, array $lines, ?string $comment, ?string $clientUuid): array
    {
        return $this->create($by, $lines, $comment, $clientUuid, fn () => ['type' => 'takeaway', 'table_id' => null, 'table_name' => null]);
    }

    /** Новый заказ и его первая отправка на кухню; $place — столик или навынос (проверяется под блокировкой). */
    private function create(Employee $by, array $lines, ?string $comment, ?string $clientUuid, callable $place): array
    {
        $this->ensureLocal();
        if ($replay = $this->replay($clientUuid)) {
            return [$replay, true];
        }

        try {
            [$order, $ticket] = DB::transaction(function () use ($by, $lines, $comment, $clientUuid, $place) {
                $store = Store::locked();
                $attributes = $place();
                $dishes = $this->dishes($lines);

                $order = Order::create($attributes + [
                    'number' => $store->takeNumber('order'),
                    'waiter_id' => $by->id,
                    'waiter_name' => $by->name,
                    'comment' => $this->clean($comment, 200),
                    'status' => 'new',
                ]);
                $ticket = $this->send($order, $by, 1, $lines, $dishes, $comment, $clientUuid);
                $order->refreshStatus();
                $order->save();

                return [$order, $ticket];
            });
        } catch (UniqueConstraintViolationException $e) {
            // тот же заказ пришёл дважды одновременно
            return [$this->replay($clientUuid) ?? throw $e, true];
        }

        $this->printer->kitchenTicket($order, $ticket);

        return [$order, false];
    }

    /**
     * Дозаказ к открытому заказу: отдельная отправка на кухню и отдельный кухонный чек только на новые позиции.
     *
     * @param  list<array{dish_id:int, qty:int, comment?:?string}>  $lines
     * @return array{0: Order, 1: bool}
     */
    public function addItems(Employee $by, Order $order, array $lines, ?string $comment, ?string $clientUuid): array
    {
        $this->ensureLocal();
        if ($replay = $this->replay($clientUuid)) {
            return [$replay, true];
        }

        try {
            [$order, $ticket] = DB::transaction(function () use ($by, $order, $lines, $comment, $clientUuid) {
                Store::locked();
                $order = $this->lockOpen($order);
                if ($order->isPaid()) {
                    $this->fail('items', 'Заказ №'.$order->number.' уже оплачен — оформите новый заказ');
                }
                $this->ensureNoTerminal($order);
                $dishes = $this->dishes($lines);
                $batch = (int) KitchenTicket::where('order_id', $order->id)->max('batch') + 1;
                $ticket = $this->send($order, $by, $batch, $lines, $dishes, $comment, $clientUuid);
                $order->bill_at = null; // гости заказали ещё — счёт будет новым
                $order->refreshStatus();
                $order->save();

                return [$order, $ticket];
            });
        } catch (UniqueConstraintViolationException $e) {
            return [$this->replay($clientUuid) ?? throw $e, true];
        }

        $this->printer->kitchenTicket($order, $ticket);

        return [$order, false];
    }

    /**
     * Отметки вместо кухонного экрана — официант, кассир или администратор:
     *  - $itemIds — выбранные позиции получают статус new, cooking, ready или served (ошиблись — можно вернуть назад,
     *    кроме уже поданных);
     *  - иначе весь заказ или одна отправка на кухню ($ticketId) — только вперёд: cooking ← new;
     *    ready ← new, cooking; served ← ready.
     * Навынос, оплаченный заранее, закрывается, когда всё выдано.
     */
    public function setStatus(Employee $by, Order $order, string $status, ?int $ticketId = null, ?array $itemIds = null): Order
    {
        $this->ensureLocal();
        if (! in_array($status, ['new', 'cooking', 'ready', 'served'], true)) {
            $this->fail('status', 'Недопустимый статус');
        }
        if ($itemIds === null && $status === 'new') {
            $this->fail('status', 'Вернуть в «новые» можно только отдельные позиции');
        }

        return DB::transaction(function () use ($by, $order, $status, $ticketId, $itemIds) {
            Store::locked();
            $order = $this->lockOpen($order);
            $query = OrderItem::where('order_id', $order->id)->whereIn('status', OrderItem::ON_BOARD)
                ->when($ticketId !== null, fn ($q) => $q->where('ticket_id', $ticketId))
                ->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds));
            if ($itemIds === null) {
                $query->whereIn('status', ['cooking' => ['new'], 'ready' => ['new', 'cooking'], 'served' => ['ready']][$status]);
            }
            $items = $query->get();
            if ($items->isEmpty()) {
                $this->fail('status', $status === 'served' ? 'Нет готовых блюд — сначала отметьте «Готово»' : 'Нет блюд, которые можно отметить «'.OrderItem::STATUS_NAMES[$status].'»');
            }
            foreach ($items as $item) {
                $this->move($item, $status);
            }

            return $this->saveOrder($order, $by);
        });
    }

    /** Официант: готовые позиции подал гостям (по умолчанию — все готовые). */
    public function serve(Order $order, ?array $itemIds = null, ?Employee $by = null): Order
    {
        $this->ensureLocal();

        return DB::transaction(function () use ($order, $itemIds, $by) {
            Store::locked();
            $order = $this->lockOpen($order);
            $items = OrderItem::where('order_id', $order->id)->where('status', 'ready')
                ->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds))->get();
            foreach ($items as $item) {
                $this->move($item, 'served');
            }

            return $this->saveOrder($order, $by);
        });
    }

    /**
     * Навынос: «Выдан» — гость забрал заказ. Всё должно быть готово. Если заказ оплачен — он закрывается,
     * если нет — ждёт оплаты (статус «выдан»).
     */
    public function handOut(Employee $by, Order $order): Order
    {
        $this->ensureLocal();

        return DB::transaction(function () use ($by, $order) {
            Store::locked();
            $order = $this->lockOpen($order);
            if (! $order->isTakeaway()) {
                $this->fail('order', 'Это заказ столика — отметьте «Подано»');
            }
            $order->load('items');
            $active = $order->activeItems();
            $cooking = $active->whereIn('status', ['new', 'cooking']);
            if ($cooking->isNotEmpty()) {
                $this->fail('items', 'Ещё не готово: '.$cooking->pluck('name')->unique()->implode(', ').'. Отметьте «Готово», когда кухня отдаст заказ');
            }
            foreach ($active->where('status', 'ready') as $item) {
                $this->move($item, 'served');
            }

            return $this->saveOrder($order, $by);
        });
    }

    /**
     * Счёт гостю: столик переходит в «ждёт счёт», гостевой чек печатается (если включена печать).
     *
     * @return array{0: Order, 1: array} [заказ, документ чека для показа на экране]
     */
    public function bill(Order $order, bool $print): array
    {
        $this->ensureLocal();
        $order = DB::transaction(function () use ($order) {
            Store::locked();
            $order = $this->lockOpen($order);
            $order->load('items');
            if ($order->activeItems()->isEmpty()) {
                $this->fail('items', 'В заказе нет блюд — счёт не нужен');
            }
            $order->bill_at ??= now();
            $order->save();

            return $order;
        });

        $document = $this->guestReceipt($order);
        if ($print) {
            $this->printer->enqueue('guest', 'Счёт №'.$order->number.' · '.mb_strtolower($order->place()), $document, $order);
        }

        return [$order, $document];
    }

    /**
     * Оплата: создаётся продажа кассы (позиции — блюда по ценам заказа).
     *
     * Заказ столика оплата закрывает (paid), столик свободен; пока что-то готовится, закрыть нельзя, готовое считается
     * поданным. Навынос можно оплатить заранее, пока блюда готовятся: заказ закроется, когда его выдадут гостю
     * (если всё уже выдано — закрывается сразу).
     * Повторная оплата уже оплаченного заказа возвращает его без второй продажи.
     * Карта через терминал кассы — $terminal: оплату проводит TerminalService, когда терминал её подтвердил.
     *
     * @return array{0: Order, 1: bool} [заказ, это повтор]
     */
    public function pay(Employee $by, Order $order, string $payment, ?int $received, ?TerminalPayment $terminal = null): array
    {
        $this->ensureLocal();

        return DB::transaction(function () use ($by, $order, $payment, $received, $terminal) {
            $store = Store::locked();
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->isPaid()) {
                return [$order, true];
            }
            if ($order->status === 'cancelled') {
                throw new RestaurantConflict("Заказ №{$order->number} отменён", $order->id);
            }
            $this->ensureNoTerminal($order, $terminal);
            $total = $this->payableTotal($order);
            $active = $order->activeItems();
            if ($terminal && $terminal->amount !== $total) {
                $this->fail('total', 'Сумма заказа изменилась, пока шла оплата на терминале');
            }
            $change = 0;
            if ($payment === 'cash') {
                $received = $received ?: $total;
                if ($received < $total) {
                    $this->fail('received', 'Получено меньше суммы счёта');
                }
                $change = $received - $total;
            } else {
                $received = null;
            }

            $sale = Sale::create([
                'number' => $store->takeNumber('sale'),
                'employee_id' => $order->waiter_id ?? $by->id,
                'customer' => mb_substr($order->place().' · заказ №'.$order->number, 0, 60),
                'payment' => $payment,
                'total' => $total,
                'cost_total' => 0,
                'received' => $received,
                'change_amount' => $change,
            ]);
            foreach ($active as $item) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => null,
                    'name' => $item->name,
                    'qty' => $item->qty,
                    'price' => $item->price,
                    'cost' => 0,
                ]);
                if ($item->status === 'ready' && ! $order->isTakeaway()) {
                    $this->move($item, 'served');
                }
            }

            $order->fill([
                'total' => $total,
                'payment' => $payment,
                'received' => $received,
                'change_amount' => $change,
                'sale_id' => $sale->id,
                'paid_at' => now(),
            ]);
            $order->bill_at ??= now();
            if ($order->isTakeaway()) {
                $this->saveOrder($order, $by); // закроется, если всё уже выдано
            } else {
                $order->fill(['status' => 'paid', 'closed_by' => $by->id])->save();
            }

            return [$order, false];
        });
    }

    /**
     * Сумма к оплате; проверяет, что заказ можно оплатить: есть блюда, а у заказа столика ничего не готовится.
     * Вызывается в транзакции с заблокированным заказом.
     */
    public function payableTotal(Order $order): int
    {
        if (! $order->isOpen() || $order->isPaid()) {
            throw new RestaurantConflict("Заказ №{$order->number} уже ".($order->status === 'cancelled' ? 'отменён' : 'оплачен'), $order->id);
        }
        $order->load('items');
        $active = $order->activeItems();
        if ($active->isEmpty()) {
            $this->fail('items', 'В заказе нет блюд к оплате');
        }
        $cooking = $active->whereIn('status', ['new', 'cooking']);
        if (! $order->isTakeaway() && $cooking->isNotEmpty()) {
            $this->fail('items', 'Ещё готовятся: '.$cooking->pluck('name')->unique()->implode(', ')
                .'. Отметьте «Готово», когда кухня отдаст блюда, или отмените эти позиции');
        }

        return (int) $active->sum(fn (OrderItem $i) => $i->qty * $i->price);
    }

    /**
     * Отменить позицию. Официант отменяет только то, что кухня ещё не начала; администратор — любую
     * неподанную или поданную (например, гость отказался). Кухня получает чек «ОТМЕНА».
     */
    public function cancelItem(Employee $by, OrderItem $item, ?string $reason): Order
    {
        $this->ensureLocal();
        [$order, $toKitchen] = DB::transaction(function () use ($by, $item) {
            Store::locked();
            $order = $this->lockOpen($item->order);
            $this->ensureChangeable($order);
            $item = OrderItem::where('order_id', $order->id)->findOrFail($item->id);
            if ($item->status === 'cancelled') {
                $this->fail('item', 'Позиция уже отменена');
            }
            if ($item->status !== 'new' && ! $this->isManager($by)) {
                $this->fail('item', 'Кухня уже начала «'.$item->name.'» — отменить может администратор');
            }
            $toKitchen = in_array($item->status, OrderItem::ON_BOARD, true) ? collect([clone $item]) : collect();
            $this->move($item, 'cancelled');

            return [$this->saveOrder($order), $toKitchen];
        });
        $this->printCancel($order, $toKitchen, $by, $reason);

        return $order;
    }

    /**
     * Отменить заказ целиком. Официант — пока кухня ничего не начала; администратор — в любой момент до оплаты.
     */
    public function cancel(Employee $by, Order $order, ?string $reason): Order
    {
        $this->ensureLocal();
        [$order, $toKitchen] = DB::transaction(function () use ($by, $order, $reason) {
            Store::locked();
            $order = $this->lockOpen($order);
            $this->ensureChangeable($order);
            $order->load('items');
            $active = $order->activeItems();
            if ($active->contains(fn (OrderItem $i) => $i->status !== 'new') && ! $this->isManager($by)) {
                $this->fail('order', 'Часть блюд уже готовится или подана — отменить заказ может администратор');
            }
            $toKitchen = $active->whereIn('status', OrderItem::ON_BOARD)->map(fn ($i) => clone $i)->values();
            foreach ($active as $item) {
                if ($item->status !== 'served') {
                    $this->move($item, 'cancelled');
                }
            }
            $order->fill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => $this->clean($reason, 100),
                'closed_by' => $by->id,
            ])->save();

            return [$order, $toKitchen];
        });
        $this->printCancel($order, $toKitchen, $by, $reason);

        return $order;
    }

    /** Повторная печать кухонного чека отправки (из заказа у официанта, кассира или в админке). */
    public function reprint(KitchenTicket $ticket): bool
    {
        if (! Settings::current()->printingEnabled()) {
            $this->fail('printer', 'Печать выключена — включите принтер в админке: Настройки → Принтер');
        }

        return $this->printer->kitchenTicket($ticket->order, $ticket, reprint: true) !== null;
    }

    public function guestReceipt(Order $order): array
    {
        $order->loadMissing('items');

        return $this->templates->guest($order, Store::current(), Settings::current());
    }

    private function move(OrderItem $item, string $to): void
    {
        if ($item->status === $to) {
            return;
        }
        $now = now();
        $item->status = $to;
        match ($to) {
            'cooking' => $item->cooking_at ??= $now,
            'ready' => $item->ready_at = $now,
            'served' => $item->served_at = $now,
            'cancelled' => $item->cancelled_at = $now,
            default => null,
        };
        if ($to === 'cooking' || $to === 'new') {
            $item->ready_at = null;
        }
        if ($to === 'new') {
            $item->cooking_at = null;
        }
        $item->save();
    }

    /** Пересчитать статус и сумму; навынос, оплаченный заранее, закрывается, когда всё выдано. */
    private function saveOrder(Order $order, ?Employee $by = null): Order
    {
        $order->refreshStatus();
        if ($order->isTakeaway() && $order->sale_id !== null && $order->status === 'served') {
            $order->status = 'paid';
            $order->closed_by ??= $by?->id;
        }
        $order->save();

        return $order;
    }

    /**
     * Создаёт отправку на кухню и её позиции.
     *
     * @param  Collection<int, Dish>  $dishes
     */
    private function send(Order $order, Employee $by, int $batch, array $lines, Collection $dishes, ?string $comment, ?string $clientUuid): KitchenTicket
    {
        $now = now();
        $ticket = KitchenTicket::create([
            'order_id' => $order->id,
            'batch' => $batch,
            'employee_id' => $by->id,
            'comment' => $this->clean($comment, 200),
            'client_uuid' => $clientUuid,
            'sent_at' => $now,
        ]);
        foreach ($lines as $line) {
            $dish = $dishes->get((int) $line['dish_id']);
            OrderItem::create([
                'order_id' => $order->id,
                'ticket_id' => $ticket->id,
                'batch' => $batch,
                'dish_id' => $dish->id,
                'name' => $dish->name,
                'price' => $dish->price,
                'qty' => (int) $line['qty'],
                'comment' => $this->clean($line['comment'] ?? null, 120),
                'status' => 'new',
                'sent_at' => $now,
            ]);
        }

        return $ticket;
    }

    /** Блюда заказа: есть в меню и не в стоп-листе. */
    private function dishes(array $lines): Collection
    {
        if ($lines === []) {
            $this->fail('items', 'Добавьте хотя бы одно блюдо');
        }
        $dishes = Dish::withTrashed()->whereIn('id', array_map(fn ($l) => (int) $l['dish_id'], $lines))->get()->keyBy('id');
        foreach ($lines as $line) {
            $dish = $dishes->get((int) $line['dish_id']);
            if (! $dish || $dish->trashed() || ! $dish->active) {
                $this->fail('items', ($dish ? '«'.$dish->name.'»' : 'Блюдо').' больше нет в меню — уберите его из заказа');
            }
            if ($dish->stop) {
                $this->fail('items', '«'.$dish->name.'» в стоп-листе — уберите его из заказа');
            }
        }

        return $dishes;
    }

    private function lockOpen(Order $order): Order
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        if (! $order->isOpen()) {
            throw new RestaurantConflict("Заказ №{$order->number} уже ".($order->status === 'paid' ? 'оплачен' : 'отменён'), $order->id);
        }

        return $order;
    }

    private function replay(?string $clientUuid): ?Order
    {
        if (! $clientUuid) {
            return null;
        }

        return KitchenTicket::where('client_uuid', $clientUuid)->first()?->order;
    }

    private function printCancel(Order $order, Collection $items, Employee $by, ?string $reason): void
    {
        if ($items->isNotEmpty()) {
            $this->printer->enqueue('cancel', 'Отмена · заказ №'.$order->number.' · '.mb_strtolower($order->place()),
                $this->templates->cancel($order, $items, Settings::current(), $by->name, $this->clean($reason, 100)), $order);
        }
    }

    /** Оплаченный заказ не меняется: деньги уже в продажах кассы. */
    private function ensureChangeable(Order $order): void
    {
        if ($order->isPaid()) {
            $this->fail('order', 'Заказ №'.$order->number.' уже оплачен — отменить нельзя. Возврат денег оформляет администратор в «Кассе и складе»');
        }
        $this->ensureNoTerminal($order);
    }

    /** Пока оплата ждёт банковский терминал, заказ не меняется и не оплачивается иначе. */
    private function ensureNoTerminal(Order $order, ?TerminalPayment $except = null): void
    {
        $pending = TerminalPayment::where('order_id', $order->id)->where('status', 'pending')
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))->exists();
        if ($pending) {
            $this->fail('terminal', 'Идёт оплата на терминале — дождитесь результата или отмените её на экране оплаты');
        }
    }

    private function isManager(Employee $e): bool
    {
        return in_array($e->role, ['owner', 'admin'], true);
    }

    private function ensureLocal(): void
    {
        if (Mode::online()) {
            throw new RestaurantConflict('Это онлайн-сервер: заказы принимает и ведёт локальный сервер ресторана');
        }
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
