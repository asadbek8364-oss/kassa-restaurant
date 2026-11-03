<?php

namespace App\Http\Controllers\Api\Restaurant\Admin;

use App\Http\Controllers\Api\Restaurant\RestaurantController;
use App\Services\Restaurant\RestaurantReportService;
use App\Support\Kassa\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Отчёты: выручка по дням, официантам и столикам, средний чек, популярные блюда. */
class ReportController extends RestaurantController
{
    public function show(Request $request, RestaurantReportService $reports): JsonResponse
    {
        $data = $this->check($request, [
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);
        [$from, $to] = $reports->defaultRange();
        if (isset($data['from'])) {
            $from = CarbonImmutable::parse($data['from'], Clock::tz());
        }
        if (isset($data['to'])) {
            $to = CarbonImmutable::parse($data['to'], Clock::tz());
        }
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'Начало периода позже конца']);
        }
        if ($from->diffInDays($to) >= RestaurantReportService::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Период — не больше года']);
        }

        return response()->json($reports->report($from, $to));
    }
}
