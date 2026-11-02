<?php

namespace App\Http\Middleware;

use App\Models\Kassa\Token;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход в «Кассу и склад» по токену сотрудника (Authorization: Bearer …).
 * Токен продлевается при активности; отключённый сотрудник теряет доступ сразу.
 */
class AuthenticateKassa
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? Token::findValid($plain) : null;

        if (! $token || ! $token->employee || ! $token->employee->active) {
            return response()->json(['message' => 'Войдите в кассу заново'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill([
                'last_used_at' => now(),
                'expires_at' => now()->addDays(config('kassa.token_ttl_days', Token::TTL_DAYS)),
            ])->save();
        }

        $request->attributes->set('kassa_employee', $token->employee);
        $request->attributes->set('kassa_token', $token);

        return $next($request);
    }
}
