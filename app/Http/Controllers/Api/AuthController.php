<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Traits\ApiResponser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @group Authentication
 *
 * APIs for user authentication and token management.
 */
class AuthController extends Controller
{
    use ApiResponser;

    /**
     * Login
     *
     * Authenticate a user and return an API bearer token.
     * The token must be included in the Authorization header for subsequent API requests.
     *
     * @bodyParam email string required The user's email address. Example: admin@example.com
     * @bodyParam password string required The user's password. Example: password123
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Login successful.",
     *   "data": {
     *     "token": "1|aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890"
     *   }
     * }
     * @response 401 {
     *   "code": 401,
     *   "status": "Error",
     *   "message": "Invalid credentials.",
     *   "data": null
     * }
     * @response 403 {
     *   "code": 403,
     *   "status": "Error",
     *   "message": "Service accounts cannot use interactive login.",
     *   "data": null
     * }
     * @response 422 {
     *   "code": 422,
     *   "status": "Error",
     *   "message": "The email field is required. (and 1 more error)",
     *   "data": {
     *     "email": ["The email field is required."],
     *     "password": ["The password field is required."]
     *   }
     * }
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            if ($user->is_service_account) {
                Auth::logout();
                Log::warning('Service account attempted interactive login', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                return $this->errorResponse('Service accounts cannot use interactive login.', 403);
            }

            $newToken = $user->createToken('api-token-'.$user->id.'-'.now()->format('YmdHis'), ['*']);
            $newToken->accessToken->forceFill([
                'expires_at' => now()->addHour(),
            ])->save();

            Log::info('User logged in successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return $this->successResponse(['token' => $newToken->plainTextToken], 'Login successful.');
        }

        Log::warning('Failed login attempt', [
            'email' => $request->email,
            'ip' => $request->ip(),
        ]);

        return $this->errorResponse('Invalid credentials.', 401);
    }
}
