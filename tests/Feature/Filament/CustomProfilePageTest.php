<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\CustomProfilePage;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Password change from the profile page must go through the vendor
 * EditProfile::save() pipeline: DB hash updated, session password_hash_web
 * refreshed (AuthenticateSession force-logs-out otherwise), redirect to the
 * dashboard. Regression: the old custom save() dropped the session refresh,
 * so every password change killed the session.
 */
class CustomProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_updates_hash_and_refreshes_session(): void
    {
        $user = User::factory()->create();

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
                        'data.name' => $user->name,
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
            Dashboard::getUrl(),
            $response->json('components.0.effects.redirect'),
        );

        $user->refresh();

        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
        $this->assertNotSame($oldHash, $user->password);

        // The dropped session refresh — the actual bug. AuthenticateSession
        // compares this value against the user's stored hash on every request.
        $this->assertSame($user->password, session('password_hash_web'));
    }

    public function test_password_change_keeps_user_logged_in(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CustomProfilePage::class)
            ->set('data.name', $user->name)
            ->set('data.password', 'NewPassword123!')
            ->set('data.password_confirmation', 'NewPassword123!')
            ->call('save');

        $this->assertAuthenticatedAs($user);
    }

    public function test_name_only_save_does_not_touch_password(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;

        Livewire::actingAs($user)
            ->test(CustomProfilePage::class)
            ->set('data.name', 'Renamed User')
            ->set('data.password', '')
            ->set('data.password_confirmation', '')
            ->call('save');

        $user->refresh();

        $this->assertSame('Renamed User', $user->name);
        $this->assertSame($oldHash, $user->password);
    }
}
