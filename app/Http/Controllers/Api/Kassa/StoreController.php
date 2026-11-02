<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Store;
use App\Services\Kassa\KassaDemoService;
use App\Support\Kassa\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Настройки магазина и управление данными — только для владельца. */
class StoreController extends KassaController
{
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

    /** Заменяет все данные демо-магазином; все сотрудники входят заново по демо-PIN. */
    public function demo(KassaDemoService $demo): JsonResponse
    {
        $demo->load();

        return response()->json(['ok' => true, 'relogin' => true]);
    }

    /** Удаляет товары и документы; сотрудники и название магазина остаются. */
    public function wipe(KassaDemoService $demo): JsonResponse
    {
        $demo->wipe();

        return response()->json(['ok' => true]);
    }
}
