<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Audit;
use App\Services\Kassa\KassaService;
use App\Support\Kassa\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends KassaController
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 6)));
        $audits = Audit::with(['items', 'employee'])->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        return response()->json(['audits' => $audits->map(fn ($a) => Presenter::audit($a, false))->all()]);
    }

    public function show(Audit $audit): JsonResponse
    {
        return response()->json(['audit' => Presenter::audit($audit)]);
    }

    /** Провести инвентаризацию: сервер фиксирует учёт и выставляет остатки по факту. */
    public function store(Request $request, KassaService $kassa): JsonResponse
    {
        $data = $this->check($request, [
            'counts' => 'required|array|min:1|max:5000',
            'counts.*.product_id' => 'required|integer',
            'counts.*.actual' => 'required|integer|min:0|max:1000000',
        ]);

        [$audit, $products] = $kassa->audit($this->me($request), $data['counts']);

        return response()->json([
            'audit' => Presenter::audit($audit->load('items')),
            'products' => Presenter::products($products),
        ], 201);
    }
}
