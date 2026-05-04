<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiEndpoint;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class CheckApiTokenAccess
{
    /**
     * Handle an incoming request.
     *
     * Verifies token has access to the requested endpoint.
     * Token inactivity expiry is handled by CheckTokenInactivity listener.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the current token from the authenticated request
        $token = $request->user()?->currentAccessToken();

        if (! $token) {
            return response()->json([
                'code' => 401,
                'status' => 'Error',
                'message' => 'Token not found or invalid.',
            ], 401);
        }

        // Token inactivity expiry is handled by CheckTokenInactivity listener
        // on the TokenAuthenticated event (fires before Sanctum updates last_used_at).

        $requestPath = $request->path();

        if (! $this->tokenCanAccessEndpoint($token, $requestPath)) {
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

        // If no restrictions, allow all (backward compatibility)
        if (empty($allowedEndpoints)) {
            return true;
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
