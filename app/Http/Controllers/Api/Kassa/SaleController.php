<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Sale;
use App\Models\Kassa\Store;
use App\Services\Kassa\KassaService;
use App\Support\Kassa\Presenter;
use App\Support\Kassa\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleController extends KassaController
{
    /** Последние чеки; ?number=1023 — поиск чека по номеру. */
    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 12)));
        $sales = Sale::with('employee')->withCount('refunds')->withSum('items as units', 'qty')->withSum('items as shortage_units', 'shortage')
            ->when($request->filled('number'), fn ($q) => $q->where('number', (int) $request->query('number')))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        return response()->json(['sales' => $sales->map(fn ($s) => Presenter::saleSummary($s))->all()]);
    }

    public function show(Sale $sale): JsonResponse
    {
        return response()->json(['sale' => Presenter::sale($sale)]);
    }

    /** Пробить чек: сервер сам берёт цены, считает итог и сдачу, списывает остатки. */
    public function store(Request $request, KassaService $kassa): JsonResponse
    {
        $data = $this->check($request, [
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:10000',
            'payment' => ['required', Rule::in(Sale::PAYMENTS)],
            'received' => 'nullable|integer|min:0|max:100000000000',
            'customer' => 'nullable|string|max:60',
            'client_uuid' => 'nullable|uuid',
        ]);

        [$sale, $products, $replay] = $kassa->sell(
            $this->me($request), $data['items'], $data['payment'], $data['customer'] ?? null,
            isset($data['received']) ? (int) $data['received'] : null, $data['client_uuid'] ?? null,
        );

        return response()->json([
            'sale' => Presenter::sale($sale->fresh()),
            'products' => Presenter::products($products),
            'replay' => $replay,
        ], $replay ? 200 : 201);
    }

    /**
     * Очередь чеков, пробитых на устройстве без связи. Каждый чек обрабатывается отдельно и в своём магазине;
     * ответ — результат по каждому client_uuid: created, replay (уже был) или rejected (с причиной).
     * Правило конфликтов по остаткам — в KassaService::syncOffline.
     */
    public function sync(Request $request, KassaService $kassa, StoreContext $context): JsonResponse
    {
        $data = $this->check($request, ['sales' => 'required|array|min:1|max:50']);
        $me = $this->me($request);
        $current = $context->store();

        $results = [];
        $changed = collect();
        foreach ($data['sales'] as $raw) {
            $uuid = is_array($raw) && is_string($raw['client_uuid'] ?? null) ? $raw['client_uuid'] : null;
            try {
                $sale = $this->checkData(is_array($raw) ? $raw : [], [
                    'client_uuid' => 'required|uuid',
                    'store_id' => 'required|integer',
                    'sold_at' => 'nullable|date',
                    'payment' => ['required', Rule::in(Sale::PAYMENTS)],
                    'customer' => 'nullable|string|max:60',
                    'received' => 'nullable|integer|min:0|max:100000000000',
                    'client_total' => 'nullable|integer|min:0|max:100000000000',
                    'items' => 'required|array|min:1|max:100',
                    'items.*.product_id' => 'required|integer',
                    'items.*.qty' => 'required|integer|min:1|max:10000',
                    'items.*.name' => 'nullable|string|max:80',
                    'items.*.price' => 'nullable|integer|min:0|max:100000000000',
                ]);
            } catch (ValidationException $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'rejected', 'message' => collect($e->errors())->flatten()->first()];

                continue;
            }

            $store = Store::find($sale['store_id']);
            if (! $store || ! $me->worksIn($store)) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'rejected', 'message' => 'Чек пробит в магазине, где этот сотрудник не работает'];

                continue;
            }

            $results[] = $context->run($store, function () use ($kassa, $me, $sale, $store, $current, $changed) {
                [$saved, $products, $replay] = $kassa->syncOffline($me, $sale);
                if ($store->id === $current->id) {
                    $products->each(fn ($p) => $changed->put($p->id, $p));
                }
                $saved = $saved->fresh(['items', 'refunds', 'employee']);

                return [
                    'client_uuid' => $sale['client_uuid'],
                    'status' => $replay ? 'replay' : 'created',
                    'store_id' => $store->id,
                    'sale' => Presenter::saleSummary($saved) + [
                        'shortage_units' => (int) $saved->items->sum('shortage'),
                        'client_total' => $saved->client_total,
                    ],
                ];
            });
        }

        return response()->json([
            'results' => $results,
            'products' => Presenter::products($changed->values()),
        ]);
    }
}
