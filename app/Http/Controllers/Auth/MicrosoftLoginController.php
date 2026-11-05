<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PanelHome;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class MicrosoftLoginController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('microsoft')->scopes(['openid', 'profile', 'email'])->redirect();
    }

    public function callback()
    {
        // Keeps Socialite's built-in state (CSRF) validation — never stateless().
        $oauth = Socialite::driver('microsoft')->user();

        $email = Str::lower(trim((string) $oauth->getEmail()));
        abort_unless($email !== '', 422, 'Microsoft did not return an email address.');

        // lower(email) match: legacy rows may hold mixed case, and sqlite tests are
        // binary-collated. New users are stored lowercase (same normalization as
        // User::upsertFromPicEmails).
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        $wasCreated = $user === null;

        if ($wasCreated) {
            $user = User::create([
                'name' => $oauth->getName() ?: (string) strtok($email, '@'),
                'email' => $email,
                // Column is NOT nullable; random and never used for login.
                'password' => Str::password(40),
                'email_verified_at' => now(),
                // Deliberately no role — canAccessPanel() denies until an admin
                // grants one in User Management.
            ]);
        }

        // Gate check before login — nothing to log out afterwards.
        if (! $user->canAccessPanel(Filament::getPanel('admin'))) {
            return redirect()
                ->route('filament.admin.auth.login')
                ->with('microsoft_error', $wasCreated
                    ? 'Your account has been created — an admin must grant you access before you can sign in.'
                    : 'Your account does not have access to the portal. Please contact an admin.');
        }

        Auth::login($user);

        return redirect(PanelHome::urlFor($user)); // same destination as the password form
    }
}
