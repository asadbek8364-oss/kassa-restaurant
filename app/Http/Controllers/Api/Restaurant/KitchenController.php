<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Models\Restaurant\Dish;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\PrintJob;
use App\Services\Restaurant\KitchenBoard;
use App\Services\Restaurant\OrderService;
use App\Services\Restaurant\Printing\PrintQueue;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Экран кухни (KDS): доска заказов, отметки «начать готовить» / «готово» / «выдано», повторная печать,
 * печать из браузера, стоп-лист.
 */
class KitchenController extends RestaurantController
{
    public function __construct(private readonly OrderService $orders) {}

    public function board(KitchenBoard $board): JsonResponse
    {
        return response()->json($board->build());
    }

    public function start(KitchenTicket $ticket): JsonResponse
    {
        return $this->done($this->orders->startTicket($ticket));
    }

    public function ready(KitchenTicket $ticket): JsonResponse
    {
        return $this->done($this->orders->readyTicket($ticket));
    }

    public function served(KitchenTicket $ticket): JsonResponse
    {
        return $this->done($this->orders->serveTicket($ticket));
    }

    /** Одна позиция: new → cooking → ready (или назад, если отметили по ошибке). */
    public function item(Request $request, OrderItem $item): JsonResponse
    {
        $data = $this->check($request, ['status' => ['required', Rule::in(['new', 'cooking', 'ready'])]]);

        return $this->done($this->orders->setItemStatus($item, $data['status']));
    }

    /** Повторная печать кухонного чека этой отправки. */
    public function reprint(KitchenTicket $ticket): JsonResponse
    {
        $this->orders->reprint($ticket);

        return response()->json(['ok' => true]);
    }

    /** Печать из браузера: экран кухни забирает задание (второй экран его уже не получит). */
    public function claimPrint(PrintJob $job, PrintQueue $queue): JsonResponse
    {
        if (! $queue->claimForBrowser($job)) {
            return response()->json(['message' => 'Этот чек уже напечатан другим экраном'], 409);
        }

        return response()->json(['job' => Presenter::printJob($job->fresh(), true)]);
    }

    /** Блюда меню для стоп-листа кухни. */
    public function menu(): JsonResponse
    {
        return response()->json(['dishes' => Dish::where('active', true)->orderBy('name')->get()->map(fn ($d) => Presenter::dish($d))->all()]);
    }

    /** Стоп-лист с кухни: блюдо закончилось или снова есть. */
    public function stop(Request $request, Dish $dish): JsonResponse
    {
        $data = $this->check($request, ['stop' => 'required|boolean']);
        $dish->update(['stop' => (bool) $data['stop']]);

        return response()->json(['dish' => Presenter::dish($dish)]);
    }

    private function done($order): JsonResponse
    {
        return response()->json(['ok' => true, 'order_status' => $order->status]);
    }
}
