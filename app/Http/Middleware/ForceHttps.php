<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceHttps
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (!$this->isSecure($request) && app()->environment('production')) {
            return redirect()->secure($request->getRequestUri());
        }

        return $next($request);
    }

    /**
     * Check if the request is secure.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function isSecure(Request $request): bool
    {
        if ($request->secure()) {
            return true;
        }

        // Check for common proxy headers
        $headers = [
            'HTTP_X_FORWARDED_PROTO',
            'HTTP_FRONT_END_HTTPS',
            'HTTP_X_FORWARDED_SSL',
        ];

        foreach ($headers as $header) {
            if (
                $request->server($header) !== null &&
                (
                    $request->server($header) === 'on' ||
                    $request->server($header) === 'https'
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
