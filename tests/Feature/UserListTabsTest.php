<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Users-page tabs (PROJ-025 + directory sync). Regression pin for the 89e055f
 * runtime break: the page imported Filament\Tables\Tabs\Tab (nonexistent in
 * Filament v3 — resource tabs are Filament\Resources\Components\Tab, and they
 * have NO visible()) so /admin/users 500'd for every viewer.
 */
class UserListTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_service_tab_is_permission_gated(): void
    {
        $admin = User::factory()->create()->givePermissionTo('view users', 'manage service accounts');

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertSuccessful()
            ->assertSee('Service Accounts');

        $plain = User::factory()->create()->givePermissionTo('view users');

        Livewire::actingAs($plain)
            ->test(ListUsers::class)
            ->assertSuccessful()
            ->assertDontSee('Service Accounts');
    }

    public function test_default_tab_lists_only_can_login_humans(): void
    {
        User::factory()->create(['name' => 'Zed Portalman', 'can_login' => true]);
        User::factory()->create(['name' => 'Zed Directoryman', 'can_login' => false]);

        Livewire::actingAs($this->viewer())
            ->test(ListUsers::class) // no activeTab → first tab (Can Log In)
            ->assertSuccessful()
            ->assertSee('Zed Portalman')
            ->assertDontSee('Zed Directoryman');
    }

    public function test_directory_tab_lists_only_no_login_humans(): void
    {
        User::factory()->create(['name' => 'Zed Portalman', 'can_login' => true]);
        User::factory()->create(['name' => 'Zed Directoryman', 'can_login' => false]);

        Livewire::actingAs($this->viewer())
            ->test(ListUsers::class, ['activeTab' => 'directory'])
            ->assertSuccessful()
            ->assertSee('Zed Directoryman')
            ->assertDontSee('Zed Portalman');
    }

    public function test_service_accounts_tab_never_leaks_into_the_other_tabs(): void
    {
        User::factory()->create(['name' => 'Zed Servicebot', 'is_service_account' => true, 'can_login' => true]);

        Livewire::actingAs($this->viewer()) // viewer: no service-accounts permission
            ->test(ListUsers::class)
            ->assertSuccessful()
            ->assertDontSee('Zed Servicebot');
    }

    private function viewer(): User
    {
        return User::factory()->create()->givePermissionTo('view users');
    }
}
