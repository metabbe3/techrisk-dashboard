<?php

declare(strict_types=1);

namespace App\Listeners;

use Laravel\Sanctum\Events\TokenAuthenticated;

class CheckTokenInactivity
{
    /**
     * Handle the event.
     *
     * Checks if the token has been inactive for more than 30 days.
     * If so, deletes the token and throws an exception to prevent authentication.
     */
    public function handle(TokenAuthenticated $event): void
    {
        $token = $event->token;

        // Never-used tokens are not expired
        if (! $token->last_used_at) {
            return;
        }

        $daysSinceLastUse = abs(now()->diffInDays($token->last_used_at));

        if ($daysSinceLastUse > 30) {
            // Delete the expired token
            $token->delete();

            // Abort with 401 to prevent the request from proceeding
            abort(401, 'Token has expired due to inactivity (30 days). Please request a new token.');
        }
    }
}
