<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Income;
use App\Models\Kassa\Product;
use App\Models\Kassa\Store;
use App\Services\Kassa\KassaDemoService;
use App\Services\Kassa\KassaReportService;
use App\Support\Kassa\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Всё, что нужно приложению при старте: кто вошёл, магазин, сотрудники, каталог.
 * Владельцу — ещё список всех магазинов для переключения.
 */
class BootstrapController extends KassaController
{
    public function __invoke(Request $request, KassaReportService $reports): JsonResponse
    {
        $me = $this->me($request);
        $store = Store::current();

        return response()->json([
            'employee' => Presenter::employee($me),
            'store' => Presenter::store($store),
            'stores' => $me->isOwner()
                ? Store::orderBy('id')->get()->map(fn ($s) => Presenter::store($s))->all()
                : [Presenter::store($store)],
            'employees' => Employee::orderBy('name')->get()->map(fn ($e) => Presenter::employee($e))->all(),
            'products' => Presenter::products(Product::orderBy('name')->get()),
            'popular' => $reports->popular(),
            'features' => ['demo' => KassaDemoService::allowed()],
            'suppliers' => Income::whereNotNull('supplier')->where('supplier', '!=', Income::OPENING_SUPPLIER)
                ->distinct()->orderBy('supplier')->pluck('supplier')->all(),
        ]);
    }
}
