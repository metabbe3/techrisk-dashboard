<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponser;

/**
 * @group Token Management
 *
 * APIs for managing your API token.
 */
class TokenController extends Controller
{
    use ApiResponser;

    /**
     * Logout
     *
     * Revoke the current API token. After calling this endpoint, the token will no longer be valid.
     *
     * @authenticated
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Token revoked successfully.",
     *   "data": null
     * }
     * @response 401 {
     *   "code": 401,
     *   "status": "Error",
     *   "message": "Unauthenticated.",
     *   "data": null
     * }
     */
    public function logout()
    {
        $token = request()->user()->currentAccessToken();

        if ($token) {
            $token->forceFill(['disabled_at' => now()])->save();
        }

        return $this->successResponse(null, 'Token revoked successfully.');
    }

    /**
     * Token Info
     *
     * Get information about the current API token including expiration and permissions.
     *
     * @authenticated
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": null,
     *   "data": {
     *     "name": "api-token-1-20260520120000",
     *     "expires_at": "2026-11-20T12:00:00.000000Z",
     *     "last_used_at": "2026-05-20T10:30:00.000000Z",
     *     "allowed_endpoints": ["incidents", "labels"],
     *     "abilities": ["*"],
     *     "has_pii_access": true,
     *     "is_expired": false,
     *     "is_disabled": false
     *   }
     * }
     * @response 401 {
     *   "code": 401,
     *   "status": "Error",
     *   "message": "Unauthenticated.",
     *   "data": null
     * }
     */
    public function info()
    {
        $token = request()->user()->currentAccessToken();

        return $this->successResponse([
            'name' => $token->name,
            'expires_at' => $token->expires_at,
            'last_used_at' => $token->last_used_at,
            'allowed_endpoints' => $token->allowed_endpoints,
            'abilities' => $token->abilities,
            'has_pii_access' => $token->can('read:pii'),
            'is_expired' => $token->isExpired(),
            'is_disabled' => $token->isDisabled(),
        ]);
    }
}
