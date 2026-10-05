<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Support\PanelHome;
use Filament\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    public static function getAuthRoute(): string
    {
        return route('filament.admin.auth.login');
    }

    protected function getRedirectUrl(): string
    {
        return PanelHome::urlFor(auth()->user());
    }
}
