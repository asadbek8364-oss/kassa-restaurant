<?php

namespace App\Services\Restaurant;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Конфликт состояния (409): на столике уже открыт заказ, заказ уже закрыт, это онлайн-сервер и т. п.
 * В ответе — сообщение и, если есть, заказ, к которому стоит перейти.
 */
class RestaurantConflict extends RuntimeException
{
    public function __construct(string $message, private readonly ?int $orderId = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'order_id' => $this->orderId], 409);
    }
}
