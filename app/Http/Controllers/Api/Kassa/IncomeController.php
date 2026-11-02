<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Income;
use App\Services\Kassa\KassaService;
use App\Support\Kassa\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeController extends KassaController
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 10)));
        $incomes = Income::with(['items', 'employee'])->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        return response()->json(['incomes' => $incomes->map(fn ($d) => Presenter::income($d, false))->all()]);
    }

    public function show(Income $income): JsonResponse
    {
        return response()->json(['income' => Presenter::income($income)]);
    }

    /** Провести приход: остатки растут, закупочная цена товара обновляется. */
    public function store(Request $request, KassaService $kassa): JsonResponse
    {
        $data = $this->check($request, [
            'supplier' => 'nullable|string|max:60',
            'items' => 'required|array|min:1|max:200',
            'items.*.product_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:1000000',
            'items.*.cost' => 'required|integer|min:0|max:100000000000',
        ]);

        [$income, $products] = $kassa->receive($this->me($request), $data['supplier'] ?? null, $data['items']);

        return response()->json([
            'income' => Presenter::income($income->load('items')),
            'products' => Presenter::products($products),
        ], 201);
    }
}
