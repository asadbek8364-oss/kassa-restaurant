<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Store;
use App\Services\Kassa\KassaDemoService;
use App\Services\Kassa\KassaForecastService;
use App\Services\Kassa\KassaReportService;
use App\Support\Kassa\Clock;
use App\Support\Kassa\Presenter;
use App\Support\Kassa\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Магазины и их настройки, управление данными — только для владельца. */
class StoreController extends KassaController
{
    /** Периоды сводки по магазинам. */
    public const SUMMARY_PERIODS = ['today', 'week', 'month'];

    /**
     * Сводка по всем магазинам: выручка, чеки, прибыль, склад и что заканчивается — по каждому и в сумме.
     * Каждый магазин считается теми же отчётами, что и сам магазин, в своём контексте.
     */
    public function index(Request $request, StoreContext $context, KassaReportService $reports, KassaForecastService $forecasts): JsonResponse
    {
        $data = $this->check($request, ['period' => ['nullable', Rule::in(self::SUMMARY_PERIODS)]]);
        $period = $data['period'] ?? 'today';
        $from = $reports->periodStart($period);
        $current = $context->id();

        $rows = Store::orderBy('id')->get()->map(fn (Store $store) => $context->run($store, function () use ($store, $from, $reports, $forecasts, $current) {
            $f = $forecasts->overview();

            return Presenter::store($store) + [
                'current' => $store->id === $current,
                'summary' => $reports->summary($from),
                'warehouse' => $reports->warehouse(),
                'low_stock' => count($reports->lowStock()),
                'running_out' => $f['running_out_count'],
                'dead_frozen' => $f['dead_frozen'],
            ];
        }))->all();

        $sum = fn (string $group, string $key) => array_sum(array_map(fn ($r) => $r[$group][$key], $rows));
        $revenue = $sum('summary', 'revenue');
        $profit = $sum('summary', 'profit');
        $checks = $sum('summary', 'checks');

        return response()->json([
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => Clock::today()->toDateString(),
            'stores' => $rows,
            'total' => [
                'stores' => count($rows),
                'revenue' => $revenue,
                'gross' => $sum('summary', 'gross'),
                'refunds' => $sum('summary', 'refunds'),
                'checks' => $checks,
                'units' => $sum('summary', 'units'),
                'avg' => $checks ? (int) round($sum('summary', 'gross') / $checks) : 0,
                'profit' => $profit,
                'margin_pct' => $revenue > 0 ? (int) round($profit / $revenue * 100) : null,
                'warehouse_cost' => $sum('warehouse', 'cost'),
                'warehouse_units' => $sum('warehouse', 'units'),
                'low_stock' => array_sum(array_column($rows, 'low_stock')),
                'running_out' => array_sum(array_column($rows, 'running_out')),
                'dead_frozen' => array_sum(array_column($rows, 'dead_frozen')),
            ],
        ]);
    }

    /** Новый магазин: пустой каталог, своя нумерация документов; продавцов владелец добавит в нём. */
    public function create(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'name' => 'required|string|max:60',
            'address' => 'nullable|string|max:120',
        ]);
        if (Store::count() >= Store::MAX_STORES) {
            throw ValidationException::withMessages(['name' => 'Можно создать не больше '.Store::MAX_STORES.' магазинов']);
        }
        $name = trim($data['name']);
        // сравнение в PHP: LOWER() в SQLite не переводит кириллицу в нижний регистр
        if (Store::pluck('name')->contains(fn ($n) => mb_strtolower($n) === mb_strtolower($name))) {
            throw ValidationException::withMessages(['name' => "Магазин «{$name}» уже есть"]);
        }

        $store = Store::create(['name' => $name, 'address' => trim((string) ($data['address'] ?? '')) ?: null]);

        return response()->json(['store' => Presenter::store($store)], 201);
    }

    /** Переключиться на другой магазин: на этом устройстве владелец работает уже в нём. */
    public function switch(Request $request, Store $store, StoreContext $context): JsonResponse
    {
        $request->attributes->get('kassa_token')->forceFill(['store_id' => $store->id])->save();
        $context->set($store);

        return response()->json(['store' => Presenter::store($store)]);
    }

    /** Название и адрес текущего магазина. */
    public function update(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'name' => 'required|string|max:60',
            'address' => 'nullable|string|max:120',
        ]);
        $store = Store::current();
        $store->update(['name' => trim($data['name']), 'address' => trim((string) ($data['address'] ?? '')) ?: null]);

        return response()->json(['store' => Presenter::store($store)]);
    }

    /** Заменяет все данные демо-магазинами; все сотрудники входят заново по демо-PIN. */
    public function demo(KassaDemoService $demo): JsonResponse
    {
        abort_unless(KassaDemoService::allowed(), 404, 'Демо-данные на этом сервере отключены');
        $demo->load();

        return response()->json(['ok' => true, 'relogin' => true]);
    }

    /**
     * Удаляет товары и документы текущего магазина; сотрудники и название остаются.
     * Нужна только для выхода из демо, поэтому на рабочем сервере недоступна.
     */
    public function wipe(KassaDemoService $demo): JsonResponse
    {
        abort_unless(KassaDemoService::allowed(), 404, 'Очистка данных на этом сервере отключена');
        $demo->wipe();

        return response()->json(['ok' => true]);
    }
}
