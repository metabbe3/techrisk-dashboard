<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    // Use default view, add Request Access link via JavaScript
    public static function getAuthRoute(): string
    {
        return route('filament.admin.auth.login');
    }
}

