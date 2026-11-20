<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Owner-only guardrail (owner 2026-11-20): unlock the login-disabled Entra
 * directory rows so every tenant identity can sign in via SSO. Flipping
 * can_login grants SSO SIGN-IN only — panel access still requires a role,
 * which the SSO callback JIT-assigns (team-viewer) on first login. NEVER
 * flip can_login from the login flow itself: it would silently undo admin
 * revocations.
 */
class EnableSsoLogin extends Command
{
    protected $signature = 'users:enable-sso-login
        {--email= : Unlock one directory row by email}
        {--all : Unlock EVERY login-disabled row (whole tenant — requires --confirm)}
        {--confirm : Explicit confirmation for --all}';

    protected $description = 'Unlock login-disabled (can_login=false) directory rows for SSO sign-in — owner-only bulk action';

    public function handle(): int
    {
        if ($this->option('email') !== null) {
            $email = strtolower(trim((string) $this->option('email')));
            $user = User::whereRaw('lower(email) = ?', [$email])->first();

            if (! $user) {
                $this->error("No user with email {$email}.");

                return self::FAILURE;
            }

            $user->update(['can_login' => true]);
            $this->info("{$user->name} <{$user->email}> can now sign in via SSO.");

            return self::SUCCESS;
        }

        if (! $this->option('all')) {
            $this->error('Pass --email=... for one user, or --all --confirm for the whole tenant.');

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->error('--all unlocks EVERY login-disabled row (whole tenant). Re-run with --confirm.');

            return self::FAILURE;
        }

        $count = User::where('can_login', false)->update(['can_login' => true]);

        $this->info("Unlocked {$count} row(s) for SSO sign-in. Panel access still needs a role — the SSO callback assigns team-viewer on first login (admins grant stronger roles in User Management).");

        return self::SUCCESS;
    }
}
