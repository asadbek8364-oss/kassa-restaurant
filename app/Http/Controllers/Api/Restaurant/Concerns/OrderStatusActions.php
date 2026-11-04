<?php

namespace App\Http\Controllers\Api\Restaurant\Concerns;

use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Services\Restaurant\OrderService;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Экран кухни только показывает заказы, поэтому отметки «готовится», «готово», «подано / выдано» и повторную печать
 * кухонного чека делают официант, кассир и администратор — одинаково в своих приложениях.
 */
trait OrderStatusActions
{
    /** Статус всего заказа, одной отправки на кухню (ticket_id) или выбранных позиций (item_ids). */
    public function status(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        $data = $this->check($request, [
            'status' => ['required', Rule::in(['new', 'cooking', 'ready', 'served'])],
            'ticket_id' => 'nullable|integer',
            'item_ids' => 'nullable|array|min:1|max:200',
            'item_ids.*' => 'integer',
        ]);
        $order = $orders->setStatus(
            $this->me($request), $order, $data['status'],
            isset($data['ticket_id']) ? (int) $data['ticket_id'] : null,
            isset($data['item_ids']) ? array_map('intval', $data['item_ids']) : null,
        );

        return response()->json(['order' => Presenter::order($order->fresh())]);
    }

    /** Навынос: «Выдан» — гость забрал заказ (оплаченный заказ закрывается). */
    public function handout(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        $order = $orders->handOut($this->me($request), $order);

        return response()->json(['order' => Presenter::order($order->fresh())]);
    }

    /** Повторить кухонный чек этой отправки на принтер кухни. */
    public function reprint(Order $order, KitchenTicket $ticket, OrderService $orders): JsonResponse
    {
        abort_unless($ticket->order_id === $order->id, 404, 'Отправка не из этого заказа');
        $orders->reprint($ticket);

        return response()->json(['ok' => true]);
    }
}
