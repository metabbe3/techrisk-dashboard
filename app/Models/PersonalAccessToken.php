<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as BasePersonalAccessToken;

class PersonalAccessToken extends BasePersonalAccessToken
{
    protected $hidden = ['token'];

    protected $casts = [
        'abilities' => 'json',
        'allowed_endpoints' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'disabled_at' => 'datetime',
        'renewal_minutes' => 'integer',
    ];

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isInactive(int $thresholdDays = 90): bool
    {
        if (! $this->last_used_at) {
            return false;
        }

        return now()->diffInDays($this->last_used_at) > $thresholdDays;
    }

    public function renew(): void
    {
        // Sliding expiry: extend to now + renewal_minutes, but never shorten a
        // longer existing expiry. A 30-day token stays alive while in active use
        // (hit Jan 20 -> exp Feb 20; hit Jan 25 -> exp Feb 25), and a token with
        // a longer fixed expiry (e.g. 6 months) isn't shrunk by a shorter renewal.
        if (! $this->renewal_minutes) {
            return;
        }

        $newExpiry = now()->addMinutes($this->renewal_minutes);

        if (! $this->expires_at || $newExpiry->isAfter($this->expires_at)) {
            $this->expires_at = $newExpiry;
            $this->save();
        }
    }

    public function scopeActive($query): void
    {
        $query->whereNull('disabled_at');
    }
}
