<?php

namespace App\Http\Middleware;

use App\Models\Kassa\Store;
use App\Models\Restaurant\Settings;
use App\Support\Kassa\StoreContext;
use App\Support\Restaurant\Mode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запросы локального сервера к онлайн-серверу: Authorization: Bearer <sync-токен ресторана>.
 * Токен выдаёт онлайн-админка, в базе хранится только его хеш. Определяет ресторан запроса.
 * На локальном сервере приём синхронизации выключен (404).
 */
class AuthenticateRestaurantSync
{
    public function __construct(private readonly StoreContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Mode::local()) {
            return response()->json(['message' => 'Это локальный сервер ресторана: приём синхронизации работает только на онлайн-сервере (DEPLOY_MODE=online)'], Response::HTTP_NOT_FOUND);
        }

        $plain = (string) $request->bearerToken();
        $settings = $plain !== '' ? Settings::withoutGlobalScopes()->where('sync_token_hash', hash('sha256', $plain))->first() : null;
        $store = $settings ? Store::find($settings->store_id) : null;
        if (! $store) {
            return response()->json(['message' => 'Неверный sync-токен — выпустите новый в онлайн-админке'], Response::HTTP_UNAUTHORIZED);
        }

        $this->context->set($store);

        return $next($request);
    }
}
