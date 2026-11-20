<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ProfileSettings;
use App\Models\User;
use App\Support\PanelHome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The dedicated Setting page (owner 2026-11-20, revisi 3): change password
 * moved off the profile page. Fields: Old / New / Confirm. The session
 * password_hash_web refresh is load-bearing (AuthenticateSession force-logs
 * the user out without it) — pinned through a REAL /livewire/update POST.
 */
class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        foreach (['view incidents', 'view dashboard widgets'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }

    public function test_password_change_updates_hash_and_refreshes_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $oldHash = $user->password;

        $rendered = Livewire::actingAs($user)->test(ProfileSettings::class);
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
        $this->assertSame(PanelHome::urlFor($user), $response->json('components.0.effects.redirect'));

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
        $this->assertNotSame($oldHash, $user->password);

        // AuthenticateSession compares this against the stored hash.
        $this->assertSame($user->password, session('password_hash_web'));
    }

    public function test_password_change_redirects_non_manage_incidents_users_to_incidents_list(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        Role::firstOrCreate(['name' => 'user']);
        $user->assignRole('user');

        $this->assertFalse($user->can('manage incidents'));

        Livewire::actingAs($user)
            ->test(ProfileSettings::class)
            ->set('data.current_password', 'password')
            ->set('data.password', 'NewPassword123!')
            ->set('data.password_confirmation', 'NewPassword123!')
            ->call('save')
            ->assertRedirect(route('filament.admin.resources.incidents.index'));
    }

    public function test_wrong_old_password_is_rejected_and_password_unchanged(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $oldHash = $user->password;

        Livewire::actingAs($user)
            ->test(ProfileSettings::class)
            ->set('data.current_password', 'not-my-password')
            ->set('data.password', 'NewPassword123!')
            ->set('data.password_confirmation', 'NewPassword123!')
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $this->assertSame($oldHash, $user->refresh()->password);
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        Livewire::actingAs($user)
            ->test(ProfileSettings::class)
            ->set('data.current_password', 'password')
            ->set('data.password', 'NewPassword123!')
            ->set('data.password_confirmation', 'Different456!')
            ->call('save')
            ->assertHasFormErrors(['password']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_page_is_reachable_from_the_profile_dropdown_and_renders_the_three_fields(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo(['view incidents', 'view dashboard widgets']);

        $this->actingAs($user)->get(ProfileSettings::getUrl())->assertSuccessful()
            ->assertSee('Old password')
            ->assertSee('New password')
            ->assertSee('Confirm new password');

        // The dropdown's Setting item links here.
        $this->actingAs($user)->get('/admin')->assertSuccessful()
            ->assertSee(ProfileSettings::getUrl(), false);
    }
}
