<?php

namespace App\Http\Middleware;

use App\Models\Kassa\Store;
use App\Models\Kassa\Token;
use App\Support\Kassa\StoreContext;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход в «Кассу и склад» по токену сотрудника (Authorization: Bearer …).
 * Токен продлевается при активности; отключённый сотрудник теряет доступ сразу.
 * Здесь же выбирается магазин запроса: продавец — всегда свой, владелец — тот, на который переключился.
 *
 * AuthenticatesRequests ставит этот middleware раньше подстановки моделей из маршрута (SubstituteBindings),
 * поэтому /products/{id} ищет товар уже в магазине сотрудника.
 */
class AuthenticateKassa implements AuthenticatesRequests
{
    public function __construct(private readonly StoreContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? Token::findValid($plain) : null;
        $employee = $token?->employee;

        if (! $employee || ! $employee->active) {
            return $this->unauthorized('Войдите в кассу заново');
        }

        $storeId = $employee->isOwner() ? $token->store_id : $employee->store_id;
        $store = ($storeId ? Store::find($storeId) : null)
            ?? ($employee->isOwner() ? Store::query()->orderBy('id')->first() : null);
        if (! $store) {
            return $this->unauthorized('Магазин не найден — войдите в кассу заново');
        }

        $touch = [];
        if ($token->store_id !== $store->id) {
            $touch['store_id'] = $store->id;
        }
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $touch['last_used_at'] = now();
            $touch['expires_at'] = now()->addDays(config('kassa.token_ttl_days', Token::TTL_DAYS));
        }
        if ($touch) {
            $token->forceFill($touch)->save();
        }

        $this->context->set($store);
        $request->attributes->set('kassa_employee', $employee);
        $request->attributes->set('kassa_token', $token);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
