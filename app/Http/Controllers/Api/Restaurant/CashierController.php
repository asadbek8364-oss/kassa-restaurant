<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Models\Restaurant\Order;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\TerminalPayment;
use App\Services\Restaurant\Terminal\TerminalService;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Приложение кассира: всё, что у официанта (зал, заказы, счёт, оплата наличными со сдачей), плюс заказы навынос
 * (без столика, очередь, «Выдан») и оплата картой через банковский терминал кассы.
 *
 * Если терминал включён, карта у кассира проходит только через терминал: заказ закрывается, когда терминал
 * подтвердил оплату. Ручная отметка «оплачено картой» здесь закрыта (у официанта она осталась, как раньше).
 */
class CashierController extends WaiterController
{
    /** Заказ навынос: без столика, сразу на кухню (печатается кухонный чек «НАВЫНОС»). */
    public function takeaway(Request $request): JsonResponse
    {
        $data = $this->check($request, $this->itemRules());
        [$order, $replay] = $this->orders->openTakeaway($this->me($request), $data['items'], $data['comment'] ?? null, $data['client_uuid'] ?? null);

        return response()->json(['order' => Presenter::order($order->fresh()), 'replay' => $replay], $replay ? 200 : 201);
    }

    public function pay(Request $request, Order $order): JsonResponse
    {
        if ($request->input('payment') === 'card' && Settings::current()->terminalOn()) {
            throw ValidationException::withMessages(['payment' => 'Карта принимается через терминал: нажмите «Отправить на терминал»']);
        }

        return parent::pay($request, $order);
    }

    /** «Отправить на терминал»: сумма заказа уходит на терминал кассы, дальше экран опрашивает результат. */
    public function terminalStart(Request $request, Order $order, TerminalService $terminal): JsonResponse
    {
        $payment = $terminal->start($this->me($request), $order);

        return $this->terminalResponse($payment, 201);
    }

    /** Чем закончилась оплата на терминале: ждём, оплачено, отклонено, отменено. */
    public function terminalShow(TerminalPayment $payment, TerminalService $terminal): JsonResponse
    {
        return $this->terminalResponse($terminal->refresh($payment));
    }

    public function terminalCancel(TerminalPayment $payment, TerminalService $terminal): JsonResponse
    {
        return $this->terminalResponse($terminal->cancel($payment));
    }

    /** Тестовый режим: имитировать исход на эмуляторе — гость оплатил (approved) или банк отказал (declined). */
    public function terminalEmulate(Request $request, TerminalPayment $payment, TerminalService $terminal): JsonResponse
    {
        $data = $this->check($request, ['result' => ['required', Rule::in(['approved', 'declined'])]]);

        return $this->terminalResponse($terminal->emulate($payment, $data['result']));
    }

    private function terminalResponse(TerminalPayment $payment, int $status = 200): JsonResponse
    {
        return response()->json([
            'payment' => Presenter::terminalPayment($payment),
            'order' => Presenter::order(Order::findOrFail($payment->order_id)),
            'poll_ms' => 1500,
        ], $status);
    }
}
