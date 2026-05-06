<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthorized');
        }

        $canAccess = Cache::remember("user_{$user->id}_api_access", 300, fn () => $user->can('access api'));

        if (! $canAccess) {
            abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
