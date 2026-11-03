<?php

namespace App\Http\Controllers\Api\Restaurant\Admin;

use App\Http\Controllers\Api\Restaurant\RestaurantController;
use App\Models\Kassa\Store;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\SyncLog;
use App\Services\Restaurant\Sync\SyncClient;
use App\Services\Restaurant\Sync\SyncException;
use App\Services\Restaurant\Sync\SyncStatus;
use App\Support\Kassa\Clock;
use App\Support\Restaurant\Mode;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Режим сервера и синхронизация.
 * Локальный сервер: адрес онлайн-сервера и sync-токен, «Проверить связь», «Синхронизировать сейчас», журнал.
 * Онлайн-сервер: выпуск и отзыв sync-токена ресторана, журнал принятых запросов.
 */
class SyncController extends RestaurantController
{
    public function show(SyncStatus $status): JsonResponse
    {
        return response()->json([
            'status' => $status->summary(),
            'log' => SyncLog::orderByDesc('id')->limit(30)->get()->map(fn ($l) => Presenter::syncLog($l))->all(),
        ]);
    }

    /** Локальный сервер: куда и с каким токеном синхронизироваться. */
    public function update(Request $request, SyncStatus $status): JsonResponse
    {
        $this->onlyLocal();
        $data = $this->check($request, [
            'url' => 'required|string|max:200|url',
            'token' => 'nullable|string|min:20|max:200',
        ]);
        $url = rtrim(trim($data['url']), '/');
        if (! str_starts_with($url, 'https://') && app()->environment('production')) {
            throw ValidationException::withMessages(['url' => 'Нужен адрес с https:// — токен нельзя передавать открытым текстом']);
        }
        $settings = Settings::current();
        $token = trim((string) ($data['token'] ?? ''));
        if ($token === '' && (string) $settings->sync_token === '') {
            throw ValidationException::withMessages(['token' => 'Введите sync-токен, выпущенный в онлайн-админке']);
        }
        $settings->sync_url = $url;
        if ($token !== '') {
            $settings->sync_token = $token;
        }
        $settings->save();

        return $this->show($status);
    }

    public function ping(SyncClient $client): JsonResponse
    {
        $this->onlyLocal();
        try {
            $answer = $client->ping();
        } catch (SyncException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Связь есть: онлайн-сервер ресторана «'.($answer['store']['name'] ?? '?').'»', 'answer' => $answer]);
    }

    /** «Синхронизировать сейчас». */
    public function run(SyncClient $client, SyncStatus $status): JsonResponse
    {
        $this->onlyLocal();
        @set_time_limit(300);
        $log = $client->run(Store::current(), 'manual');

        return response()->json([
            'ok' => $log->status === 'ok',
            'run' => Presenter::syncLog($log),
            'status' => $status->summary(),
        ], $log->status === 'ok' ? 200 : 422);
    }

    /** Онлайн-сервер: новый sync-токен ресторана. Показывается один раз; прежний перестаёт работать. */
    public function issueToken(): JsonResponse
    {
        $this->onlyOnline();
        $plain = 'rs_'.Str::random(48);
        Settings::current()->forceFill([
            'sync_token_hash' => hash('sha256', $plain),
            'sync_token_hint' => '…'.substr($plain, -4),
            'sync_token_issued_at' => now(),
        ])->save();

        return response()->json([
            'token' => $plain,
            'issued_at' => Clock::iso(now()),
            'message' => 'Скопируйте токен сейчас — он больше не будет показан. Введите его в админке локального сервера: Настройки → Синхронизация',
        ], 201);
    }

    public function revokeToken(): JsonResponse
    {
        $this->onlyOnline();
        Settings::current()->forceFill(['sync_token_hash' => null, 'sync_token_hint' => null, 'sync_token_issued_at' => null])->save();

        return response()->json(['ok' => true]);
    }

    private function onlyLocal(): void
    {
        if (Mode::online()) {
            abort(409, 'Это онлайн-сервер: синхронизацию запускает локальный сервер ресторана');
        }
    }

    private function onlyOnline(): void
    {
        if (Mode::local()) {
            abort(409, 'Sync-токен выпускается в админке онлайн-сервера (DEPLOY_MODE=online)');
        }
    }
}
