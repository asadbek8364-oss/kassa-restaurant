<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Models\Restaurant\PrintJob;
use App\Services\Restaurant\KitchenBoard;
use App\Services\Restaurant\Printing\PrintQueue;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;

/**
 * Экран кухни (KDS) — пассивный настенный дисплей: только показывает заказы. Отметки «готовится», «готово»,
 * «подано / выдано» ставят официант, кассир и администратор в своих приложениях.
 * При печати из браузера экран кухни сам печатает чеки из очереди — без кнопок.
 */
class KitchenController extends RestaurantController
{
    public function board(KitchenBoard $board): JsonResponse
    {
        return response()->json($board->build());
    }

    /** Печать из браузера: экран кухни забирает задание (второй экран его уже не получит). */
    public function claimPrint(PrintJob $job, PrintQueue $queue): JsonResponse
    {
        if (! $queue->claimForBrowser($job)) {
            return response()->json(['message' => 'Этот чек уже напечатан другим экраном'], 409);
        }

        return response()->json(['job' => Presenter::printJob($job->fresh(), true)]);
    }
}
