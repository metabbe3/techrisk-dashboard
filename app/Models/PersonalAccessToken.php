<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as BasePersonalAccessToken;

class PersonalAccessToken extends BasePersonalAccessToken
{
    protected $casts = [
        'abilities' => 'json',
        'allowed_endpoints' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
