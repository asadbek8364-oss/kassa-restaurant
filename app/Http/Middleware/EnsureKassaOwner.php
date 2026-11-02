<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKassaOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->get('kassa_employee')?->isOwner()) {
            return response()->json(['message' => 'Это доступно только владельцу магазина'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
