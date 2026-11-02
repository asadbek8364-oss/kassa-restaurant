<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Sale;
use App\Services\Kassa\KassaService;
use App\Support\Kassa\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends KassaController
{
    /** Последние чеки; ?number=1023 — поиск чека по номеру. */
    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 12)));
        $sales = Sale::with('employee')->withCount('refunds')->withSum('items as units', 'qty')
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
}
