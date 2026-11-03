<?php

namespace App\Http\Middleware;

use App\Support\Restaurant\Apps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ к приложению по роли: kassa.app:admin — админка ресторана, :waiter — официант, :kitchen — кухня,
 * :kassa — касса и склад. Ставится после kassa.auth. В ответе 403 — адрес «своего» интерфейса сотрудника.
 */
class EnsureKassaApp
{
    public function handle(Request $request, Closure $next, string $app): Response
    {
        $employee = $request->attributes->get('kassa_employee');
        if (! $employee || ! Apps::allows($app, $employee->role)) {
            $home = Apps::home((string) $employee?->role);

            return response()->json([
                'message' => 'Нет доступа: '.Apps::NAMES[$app].' не для роли «'.(Apps::ROLE_NAMES[$employee?->role] ?? '—').'». Ваш интерфейс — '.Apps::url($home),
                'home' => Apps::url($home),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
