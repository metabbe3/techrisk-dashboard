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
            ]);
        }

        // JIT restricted role (owner 2026-11-20): every tenant identity gets
        // the team-viewer panel scope — their PIC/team incident slice plus
        // proof submission. Only while role-less: an admin can grant a
        // stronger role later and this never fires again. can_login=false
        // still blocks the panel (revocation survives; the unlock path is
        // users:enable-sso-login, never this callback).
        if ($user->roles()->count() === 0) {
            $user->assignRole('team-viewer');
        }

        Auth::login($user);

        // Panel users: back to where they were headed, else their panel home.
        // Users the panel still blocks (can_login=false directory rows,
        // expired access): the intended URL (their form link) or a page
        // telling them to open the link from their email — never the panel.
        $fallback = $user->canAccessPanel(Filament::getPanel('admin'))
            ? PanelHome::urlFor($user)
            : route('sso-signed-in');

        return redirect()->intended($fallback);
    }
}
