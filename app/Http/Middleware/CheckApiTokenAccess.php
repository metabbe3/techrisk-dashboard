<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiEndpoint;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiTokenAccess
{
    /**
     * Handle an incoming request.
     *
     * Verifies token has access to the requested endpoint.
     * Token disabled/expired/inactivity checks are handled by CheckTokenInactivity listener.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token) {
            return response()->json([
                'code' => 401,
                'status' => 'Error',
                'message' => 'Token not found or invalid.',
                'data' => null,
            ], 401);
        }

        $requestPath = $request->path();

        // Meta endpoints (token self-info/logout) are always reachable by a valid
        // token — endpoint restrictions gate DATA endpoints, not a token's ability
        // to inspect or disable itself. Without this, a token restricted to
        // `incidents` could never call /v1/token/info or /logout.
        // W8 fix: segment-bound (a bare str_starts_with let any future
        // api/v1/token-* sibling route inherit the bypass).
        $isMetaEndpoint = $requestPath === 'api/token'
            || $requestPath === 'api/v1/token'
            || str_starts_with($requestPath, 'api/token/')
            || str_starts_with($requestPath, 'api/v1/token/')
            || $requestPath === 'api/logout'; // token self-logout — was stranded for restricted tokens

        if (! $isMetaEndpoint && ! $this->tokenCanAccessEndpoint($token, $requestPath)) {
            \Log::warning('API token access denied', [
                'token_id' => $token->id,
                'token_name' => $token->name,
                'path' => $requestPath,
                'user_id' => $token->tokenable_id,
            ]);

            return response()->json([
                'code' => 403,
                'status' => 'Error',
                'message' => 'This token does not have permission to access this endpoint.',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Check if token can access the requested endpoint
     */
    private function tokenCanAccessEndpoint(PersonalAccessToken $token, string $path): bool
    {
        $allowedEndpoints = $token->allowed_endpoints;

        if (is_string($allowedEndpoints)) {
            $allowedEndpoints = json_decode($allowedEndpoints, true) ?? [];
        }

        if ($allowedEndpoints === null) {
            $allowedEndpoints = [];
        }

        // W8 fix (new posture): empty/null = DENY. The old "empty = allow
        // all" default made every token created without an explicit
        // endpoint selection unrestricted. Allow-all must now be stated
        // explicitly with the `*` sentinel (the Filament form writes it as
        // the default; the backfill migration stamps existing rows).
        if (in_array('*', $allowedEndpoints, true)) {
            return true;
        }

        if (empty($allowedEndpoints)) {
            return false;
        }

        // Check each allowed endpoint
        foreach ($allowedEndpoints as $endpoint) {
            try {
                $apiEndpoint = ApiEndpoint::from($endpoint);

                if ($apiEndpoint->matchesRoute($path)) {
                    return true;
                }
            } catch (\ValueError $e) {
                // Invalid endpoint enum value, skip
                continue;
            }
        }

        return false;
    }
}
