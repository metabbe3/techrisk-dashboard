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
     * Checks:
     * 1. Token has not expired (30 days after last_used_at)
     * 2. Token has access to the requested endpoint
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

        // Check 1: Verify token hasn't expired (30 days of inactivity)
        if ($this->isTokenExpired($token)) {
            $token->delete(); // Revoke expired tokens

            return response()->json([
                'code' => 401,
                'status' => 'Error',
                'message' => 'Token has expired due to inactivity (30 days). Please request a new token.',
            ], 401);
        }

        // Check 2: Verify token has access to the requested endpoint
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

        // Update last_used_at timestamp
        $token->forceFill([
            'last_used_at' => now(),
        ])->save();

        return $next($request);
    }

    /**
     * Check if token has expired (30 days of inactivity)
     */
    private function isTokenExpired(PersonalAccessToken $token): bool
    {
        if (! $token->last_used_at) {
            // Never used tokens are not expired
            return false;
        }

        $daysSinceLastUse = now()->diffInDays($token->last_used_at);

        return $daysSinceLastUse > 30;
    }

    /**
     * Check if token can access the requested endpoint
     */
    private function tokenCanAccessEndpoint(PersonalAccessToken $token, string $path): bool
    {
        // Get allowed endpoints from token
        $allowedEndpoints = $token->allowed_endpoints ?? [];

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
