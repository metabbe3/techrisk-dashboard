<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Support\PanelHome;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected static string $view = 'filament.pages.auth.login';

    protected static string $layout = 'filament.custom.login-layout';

    public static function getAuthRoute(): string
    {
        return route('filament.admin.auth.login');
    }

    protected function getRedirectUrl(): string
    {
        return PanelHome::urlFor(auth()->user());
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response === null) {
            return null; // rate-limited; the parent already notified
        }

        // Panel is SSO-first: password sign-in is the admins' break-glass only.
        // Hiding the form behind a toggle is UX, never enforcement — gate here.
        if (! Filament::auth()->user()?->hasRole('admin')) {
            Filament::auth()->logout();

            throw ValidationException::withMessages([
                'data.email' => 'Password login is restricted to admin accounts. Please use "Sign in with Microsoft".',
            ]);
        }

        return $response;
    }
}
