<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthorized');
        }

        // W8 fix: no custom cache — Spatie's permission registrar caches
        // `can()` internally and auto-invalidates on role/permission
        // changes. The old 5-minute Cache::remember kept revoked users
        // API-authorized for up to 300 s.
        if (! $user->can('access api')) {
            abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
