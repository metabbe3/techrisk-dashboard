<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Http\RedirectResponse;

class Login extends BaseLogin
{
    public static function getAuthRoute(): string
    {
        return route('filament.admin.auth.login');
    }

    protected function getRedirectUrl(): string
    {
        if (auth()->user()?->can('manage incidents')) {
            return route('filament.admin.pages.dashboard');
        }

        return route('filament.admin.resources.incidents.index');
    }
}
