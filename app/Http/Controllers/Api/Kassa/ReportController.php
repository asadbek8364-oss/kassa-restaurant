<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Services\Kassa\KassaReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends KassaController
{
    public function __construct(private readonly KassaReportService $reports) {}

    public function dashboard(): JsonResponse
    {
        return response()->json($this->reports->dashboard());
    }

    public function show(Request $request): JsonResponse
    {
        $data = $this->check($request, ['period' => ['required', Rule::in(KassaReportService::PERIODS)]]);

        return response()->json($this->reports->report($data['period']));
    }

    public function journal(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'type' => ['nullable', Rule::in(['all', 'sale', 'ret', 'inc', 'aud'])],
            'q' => 'nullable|string|max:60',
            'offset' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        return response()->json($this->reports->journal(
            $data['type'] ?? 'all', (string) ($data['q'] ?? ''), (int) ($data['offset'] ?? 0), (int) ($data['limit'] ?? 80),
        ));
    }
}
