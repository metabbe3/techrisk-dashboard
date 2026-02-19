<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
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
     *
     * @response 401 {
     *   "code": 401,
     *   "status": "Error",
     *   "message": "Invalid credentials.",
     *   "data": null
     * }
     *
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
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            $token = $user->createToken('api-token')->plainTextToken;

            Log::info('User logged in successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return $this->successResponse(['token' => $token], 'Login successful.');
        }

        Log::warning('Failed login attempt', [
            'email' => $request->email,
            'ip' => $request->ip(),
        ]);

        return $this->errorResponse('Invalid credentials.', 401);
    }
}
