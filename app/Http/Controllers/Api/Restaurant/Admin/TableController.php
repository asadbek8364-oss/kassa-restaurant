<?php

namespace App\Http\Controllers\Api\Restaurant\Admin;

use App\Http\Controllers\Api\Restaurant\RestaurantController;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Table;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Столики зала: номер или название, мест, активность. */
class TableController extends RestaurantController
{
    public function index(): JsonResponse
    {
        $open = Order::whereIn('status', Order::OPEN)->get()->keyBy('table_id');

        return response()->json([
            'tables' => Table::orderBy('sort')->orderBy('id')->get()
                ->map(fn (Table $t) => Presenter::table($t, $open->get($t->id)))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $table = Table::create($this->data($request));

        return response()->json(['table' => Presenter::table($table)], 201);
    }

    public function update(Request $request, Table $table): JsonResponse
    {
        $table->update($this->data($request, $table));

        return response()->json(['table' => Presenter::table($table, $table->openOrder())]);
    }

    public function destroy(Table $table): JsonResponse
    {
        if ($open = $table->openOrder()) {
            throw ValidationException::withMessages(['table_id' => "За столиком открыт заказ №{$open->number} — сначала закройте его"]);
        }
        $table->delete();

        return response()->json(['ok' => true]);
    }

    private function data(Request $request, ?Table $current = null): array
    {
        $data = $this->check($request, [
            'name' => 'required|string|max:20',
            'seats' => 'required|integer|min:1|max:100',
            'active' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0|max:100000',
        ]);
        $name = trim($data['name']);
        $taken = Table::when($current, fn ($q) => $q->whereKeyNot($current->id))->pluck('name')
            ->contains(fn ($n) => mb_strtolower($n) === mb_strtolower($name));
        if ($taken) {
            throw ValidationException::withMessages(['name' => "Столик «{$name}» уже есть"]);
        }

        return [
            'name' => $name,
            'seats' => (int) $data['seats'],
            'active' => (bool) ($data['active'] ?? true),
            'sort' => (int) ($data['sort'] ?? ($current?->sort ?? ((int) Table::max('sort') + 10))),
        ];
    }
}
