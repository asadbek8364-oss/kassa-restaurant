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
        abort_unless(KassaDemoService::allowed(), 404, 'Демо-данные на этом сервере отключены');
        $demo->load();

        return response()->json(['ok' => true, 'relogin' => true]);
    }

    /**
     * Удаляет товары и документы; сотрудники и название магазина остаются.
     * Нужна только для выхода из демо, поэтому на рабочем сервере недоступна.
     */
    public function wipe(KassaDemoService $demo): JsonResponse
    {
        abort_unless(KassaDemoService::allowed(), 404, 'Очистка данных на этом сервере отключена');
        $demo->wipe();

        return response()->json(['ok' => true]);
    }
}
