<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    // Only override the view to add Request Access link
    // Keep all default form behavior from parent class
    public function getView(): string
    {
        return 'filament.pages.auth.login';
    }
}

