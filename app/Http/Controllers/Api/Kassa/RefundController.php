<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Refund;
use App\Models\Kassa\Sale;
use App\Services\Kassa\KassaService;
use App\Support\Kassa\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RefundController extends KassaController
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 8)));
        $refunds = Refund::with(['sale', 'employee'])->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        return response()->json(['returns' => $refunds->map(fn ($r) => Presenter::refund($r, false))->all()]);
    }

    public function show(Refund $refund): JsonResponse
    {
        return response()->json(['return' => Presenter::refund($refund->load('items'))]);
    }

    /** Возврат по чеку: сервер проверяет количество и считает сумму по цене из чека. */
    public function store(Request $request, KassaService $kassa): JsonResponse
    {
        $data = $this->check($request, [
            'sale_id' => 'required|integer|exists:kassa_sales,id',
            'items' => 'required|array|min:1|max:100',
            'items.*.sale_item_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:10000',
            'reason' => ['required', Rule::in(Refund::REASONS)],
        ]);

        [$refund, $products] = $kassa->refund($this->me($request), Sale::findOrFail($data['sale_id']), $data['items'], $data['reason']);

        return response()->json([
            'return' => Presenter::refund($refund->load('items')),
            'products' => Presenter::products($products),
        ], 201);
    }
}
