<?php

declare(strict_types=1);

namespace App\Listeners;

use Laravel\Sanctum\Events\TokenAuthenticated;

class CheckTokenInactivity
{
    public function handle(TokenAuthenticated $event): void
    {
        $token = $event->token;

        if ($token->isDisabled()) {
            abort(401, 'Token has been disabled. Contact an administrator to re-enable.');
        }

        if ($token->isExpired()) {
            abort(401, 'Token has expired. Please request a new token.');
        }

        if ($token->isInactive(90)) {
            $token->forceFill(['disabled_at' => now()])->save();
            abort(401, 'Token disabled due to 90 days of inactivity. Contact an administrator to re-enable.');
        }

        if ($token->renewal_minutes) {
            $token->renew();
        }
    }
}
