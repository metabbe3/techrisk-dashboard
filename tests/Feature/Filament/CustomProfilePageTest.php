<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\CustomProfilePage;
use App\Models\User;
use App\Support\PanelHome;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Change-password from the profile page must go through the vendor
 * EditProfile::save() pipeline: DB hash updated, session password_hash_web
 * refreshed (AuthenticateSession force-logs-out otherwise), redirect to the
 * dashboard. Regression: the old custom save() dropped the session refresh,
 * so every password change killed the session.
 *
 * Owner 2026-11-20 (revisi): the form is OLD / NEW / CONFIRM behind a button;
 * the old password is verified with Laravel's `current_password` rule; the
 * name/email fields left the form.
 */
class CustomProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_updates_hash_and_refreshes_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $oldHash = $user->password;

        // The session refresh (EditProfile::save lines 175-179) is gated on
        // request()->hasSession(), and Livewire's own test harness disables
        // middleware — so this must be a REAL POST through the web stack,
        // exactly like the browser does in prod.
        $rendered = Livewire::actingAs($user)->test(CustomProfilePage::class);
        $snapshot = Utils::extractAttributeDataFromHtml($rendered->html(), 'wire:snapshot');

        $response = $this->actingAs($user)->withHeaders(['X-Livewire' => true])->post('/livewire/update', [
            'components' => [
                [
                    'snapshot' => json_encode($snapshot),
                    'updates' => [
                        'data.current_password' => 'password',
                        'data.password' => 'NewPassword123!',
                        'data.password_confirmation' => 'NewPassword123!',
                    ],
                    'calls' => [
                        ['method' => 'save', 'params' => [], 'path' => ''],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(
            PanelHome::urlFor($user),
            $response->json('components.0.effects.redirect'),
        );

        $user->refresh();

        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
        $this->assertNotSame($oldHash, $user->password);

        // The dropped session refresh — the actual bug. AuthenticateSession
        // compares this value against the user's stored hash on every request.
        $this->assertSame($user->password, session('password_hash_web'));
    }

    public function test_password_change_redirects_non_manage_incidents_users_to_incidents_list(): void
    {
        // user-role accounts (the seeder's `user` role) can enter the panel
        // and open /admin/profile, but the Dashboard page gate requires
        // `manage incidents` — an unconditional Dashboard redirect is a
        // guaranteed 403 for them. Login already sends them to the incidents
        // list; the profile page must agree (PanelHome is the shared rule).
        $user = User::factory()->create(['password' => Hash::make('password')]);
        Role::firstOrCreate(['name' => 'user']);
        $user->assignRole('user');

        $this->assertFalse($user->can('manage incidents'));

        $rendered = Livewire::actingAs($user)->test(CustomProfilePage::class);
        $snapshot = Utils::extractAttributeDataFromHtml($rendered->html(), 'wire:snapshot');

        $response = $this->actingAs($user)->withHeaders(['X-Livewire' => true])->post('/livewire/update', [
            'components' => [
                [
                    'snapshot' => json_encode($snapshot),
                    'updates' => [
                        'data.current_password' => 'password',
                        'data.password' => 'NewPassword123!',
                        'data.password_confirmation' => 'NewPassword123!',
                    ],
                    'calls' => [
                        ['method' => 'save', 'params' => [], 'path' => ''],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $redirect = $response->json('components.0.effects.redirect');
        $this->assertSame(route('filament.admin.resources.incidents.index'), $redirect);
        $this->assertNotSame(Dashboard::getUrl(), $redirect);
    }

    public function test_password_change_keeps_user_logged_in(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        Livewire::actingAs($user)
            ->test(CustomProfilePage::class)
            ->set('data.current_password', 'password')
            ->set('data.password', 'NewPassword123!')
            ->set('data.password_confirmation', 'NewPassword123!')
            ->call('save');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_old_password_is_rejected_and_password_unchanged(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $oldHash = $user->password;

        Livewire::actingAs($user)
            ->test(CustomProfilePage::class)
            ->set('data.current_password', 'not-my-password')
            ->set('data.password', 'NewPassword123!')
            ->set('data.password_confirmation', 'NewPassword123!')
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $user->refresh();

        $this->assertSame($oldHash, $user->password);
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        Livewire::actingAs($user)
            ->test(CustomProfilePage::class)
            ->set('data.current_password', 'password')
            ->set('data.password', 'NewPassword123!')
            ->set('data.password_confirmation', 'Different456!')
            ->call('save')
            ->assertHasFormErrors(['password']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_page_title_and_heading_say_profile(): void
    {
        // Owner 2026-11-20 (revisi): the page is the PROFILE page again —
        // the password change moved behind a button in its content.
        $user = User::factory()->create();

        $page = app(CustomProfilePage::class);
        $this->assertSame('Profile', $page->getTitle());
        $this->assertSame('Profile', $page->getHeading());

        Livewire::actingAs($user)
            ->test(CustomProfilePage::class)
            ->assertSee('Change password')
            ->assertDontSee('Update your display name or change your password.');
    }
}
