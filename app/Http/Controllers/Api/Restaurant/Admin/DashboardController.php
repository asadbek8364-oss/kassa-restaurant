<?php

namespace App\Http\Controllers\Api\Restaurant\Admin;

use App\Http\Controllers\Api\Restaurant\RestaurantController;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\PrintJob;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\Table;
use App\Services\Restaurant\RestaurantReportService;
use App\Services\Restaurant\Sync\SyncStatus;
use App\Support\Kassa\Clock;
use App\Support\Restaurant\Presenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Главный экран админки и список заказов. */
class DashboardController extends RestaurantController
{
    public function show(RestaurantReportService $reports, SyncStatus $sync): JsonResponse
    {
        $settings = Settings::current();
        $open = Order::with('items')->whereIn('status', Order::OPEN)->orderBy('id')->get();
        $lastPrintError = PrintJob::whereIn('status', ['pending', 'failed'])->whereNotNull('last_error')->orderByDesc('id')->first();

        return response()->json([
            'today' => $reports->today(),
            'tables' => [
                'total' => Table::where('active', true)->count(),
                'busy' => $open->pluck('table_id')->filter()->unique()->count(),
                'bill' => $open->whereNotNull('bill_at')->count(),
            ],
            'kitchen' => [
                'new' => (int) OrderItem::where('status', 'new')->sum('qty'),
                'cooking' => (int) OrderItem::where('status', 'cooking')->sum('qty'),
                'ready' => (int) OrderItem::where('status', 'ready')->sum('qty'),
            ],
            'open_orders' => $open->map(fn ($o) => Presenter::orderSummary($o))->values()->all(),
            'printer' => [
                'mode' => $settings->printer_mode,
                'pending' => PrintJob::where('status', 'pending')->count(),
                'failed' => PrintJob::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
                'last_error' => $lastPrintError?->last_error,
            ],
            'sync' => $sync->summary(),
        ]);
    }

    /** Заказы: открытые, за день или за период. */
    public function orders(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'filter' => ['nullable', Rule::in(['open', 'day'])],
            'date' => 'nullable|date_format:Y-m-d',
        ]);
        $query = Order::with('items')->orderByDesc('id');
        if (($data['filter'] ?? 'day') === 'open') {
            $query->whereIn('status', Order::OPEN);
        } else {
            $day = CarbonImmutable::parse($data['date'] ?? Clock::today()->toDateString(), Clock::tz())->startOfDay();
            $query->where('created_at', '>=', Clock::toDb($day))->where('created_at', '<', Clock::toDb($day->addDay()));
        }

        return response()->json(['orders' => $query->limit(300)->get()->map(fn ($o) => Presenter::orderSummary($o))->all()]);
    }

    public function order(Order $order): JsonResponse
    {
        return response()->json(['order' => Presenter::order($order)]);
    }
}
