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
        if ($this->renewal_minutes && $this->expires_at) {
            $this->expires_at = $this->expires_at->addMinutes($this->renewal_minutes);
            $this->save();
        }
    }

    public function scopeActive($query): void
    {
        $query->whereNull('disabled_at');
    }
}
