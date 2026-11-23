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

        $azureId = (string) $oauth->getId();

        // W5 fix: bind identity on the Entra OBJECT ID first — an email (=UPN)
        // match alone let a tenant-admin UPN rename silently take over any
        // local account. Legacy rows without azure_id fall back to the email
        // match and get the id stamped on first successful sign-in.
        $user = $azureId !== ''
            ? User::where('azure_id', $azureId)->first()
            : null;

        if ($user === null) {
            // lower(email) match: legacy rows may hold mixed case, and sqlite tests are
            // binary-collated. New users are stored lowercase (same normalization as
            // User::upsertFromPicEmails).
            $user = User::whereRaw('lower(email) = ?', [$email])->first();

            if ($user !== null && $azureId !== '') {
                $user->forceFill(['azure_id' => $azureId])->save();
            }
        }

        $wasCreated = $user === null;

        if ($wasCreated) {
            $user = User::create([
                'name' => $oauth->getName() ?: (string) strtok($email, '@'),
                'email' => $email,
                // Column is NOT nullable; random and never used for login.
                'password' => Str::password(40),
                'email_verified_at' => now(),
                'azure_id' => $azureId !== '' ? $azureId : null,
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

        // W5 note: can_login=false rows STILL get a session — owner design
        // (2026-11-20): directory contacts receive forwarded investigation
        // links and must be able to sign in to fill them; the panel gate
        // and zero-permission default contain the session. Do not gate
        // Auth::login here without revisiting that flow.
        Auth::login($user);

        // Panel users: back to where they were headed, else their panel home.
        // Users the panel still blocks (expired access): the intended URL
        // (their form link) or a page telling them to open the link from
        // their email — never the panel.
        $fallback = $user->canAccessPanel(Filament::getPanel('admin'))
            ? PanelHome::urlFor($user)
            : route('sso-signed-in');

        return redirect()->intended($fallback);
    }
}
