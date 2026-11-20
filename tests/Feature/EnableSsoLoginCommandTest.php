<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * users:enable-sso-login (owner-only guardrail, owner 2026-11-20): unlocks
 * login-disabled directory rows for SSO sign-in. --all is deliberately
 * double-guarded (--confirm) — it flips the whole tenant. Flipping
 * can_login grants SIGN-IN only; roles (panel access) are untouched.
 */
class EnableSsoLoginCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_email_unlocks_one_row(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $locked = User::factory()->create(['can_login' => false]);

        $this->artisan('users:enable-sso-login', ['--email' => $locked->email])->assertSuccessful();

        $this->assertTrue($locked->fresh()->can_login);
    }

    public function test_all_without_confirm_fails_and_changes_nothing(): void
    {
        User::factory()->create(['can_login' => false]);
        User::factory()->create(['can_login' => false]);

        $this->artisan('users:enable-sso-login', ['--all' => true])->assertFailed();

        $this->assertSame(2, User::where('can_login', false)->count(), 'No silent unlocks');
    }

    public function test_all_with_confirm_flips_only_locked_rows(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $lockedA = User::factory()->create(['can_login' => false]);
        $lockedB = User::factory()->create(['can_login' => false]);
        $active = User::factory()->create(['can_login' => true]);
        $active->assignRole('admin');

        $this->artisan('users:enable-sso-login', ['--all' => true, '--confirm' => true])->assertSuccessful();

        $this->assertTrue($lockedA->fresh()->can_login);
        $this->assertTrue($lockedB->fresh()->can_login);
        $this->assertTrue($active->fresh()->can_login);
        $this->assertTrue($active->fresh()->hasRole('admin'), 'Roles and names are untouched');
    }
}
