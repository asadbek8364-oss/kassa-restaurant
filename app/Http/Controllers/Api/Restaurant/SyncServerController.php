<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Kassa\Store;
use App\Models\Restaurant\SyncLog;
use App\Services\Restaurant\Sync\SyncException;
use App\Services\Restaurant\Sync\SyncServer;
use App\Support\Kassa\Presenter as KassaPresenter;
use App\Support\Restaurant\Mode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Онлайн-сервер: REST для локальных серверов ресторанов (sync-токен — middleware restaurant.sync).
 * Каждый принятый запрос записывается в журнал синхронизаций ресторана.
 */
class SyncServerController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'mode' => Mode::current(),
            'store' => KassaPresenter::store(Store::current()),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function push(Request $request, SyncServer $server): JsonResponse
    {
        @set_time_limit(300);
        $log = SyncLog::create(['direction' => 'push', 'trigger' => 'remote', 'status' => 'running', 'started_at' => now()]);
        try {
            $result = $server->push($request->json()->all());
        } catch (SyncException $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        $received = array_map(fn ($c) => $c['created'], $result['counts']) + [
            'products' => $result['products'],
            'menu' => count($result['master']['accepted']),
            'conflicts' => count($result['master']['conflicts']),
            'rejected' => count($result['rejected']),
        ];
        $log->update([
            'status' => $result['rejected'] ? 'failed' : 'ok',
            'received' => $received,
            'error' => $result['rejected'] ? collect($result['rejected'])->pluck('message')->unique()->take(3)->implode('; ') : null,
            'finished_at' => now(),
        ]);

        return response()->json($result);
    }

    public function pull(SyncServer $server): JsonResponse
    {
        $data = $server->pull();
        SyncLog::create([
            'direction' => 'pull', 'trigger' => 'remote', 'status' => 'ok', 'started_at' => now(), 'finished_at' => now(),
            'sent' => ['categories' => count($data['categories']), 'dishes' => count($data['dishes']), 'tables' => count($data['tables']), 'staff' => count($data['staff'])],
        ]);

        return response()->json($data);
    }
}
