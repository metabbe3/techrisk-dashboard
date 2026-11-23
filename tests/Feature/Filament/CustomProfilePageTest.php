<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\CustomProfilePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The profile page itself (owner 2026-11-20, revisi 3): info + contribution
 * graph only — the change-password form moved to the Setting page
 * (see ProfileSettingsTest for the save() pipeline pins).
 */
class CustomProfilePageTest extends TestCase
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

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo(['view incidents', 'view dashboard widgets']);

        return $user;
    }

    public function test_page_title_and_heading_say_profile(): void
    {
        $user = User::factory()->create();

        $page = app(CustomProfilePage::class);
        $this->assertSame('Profile', $page->getTitle());
        $this->assertSame('Profile', $page->getHeading());

        Livewire::actingAs($user)
            ->test(CustomProfilePage::class)
            ->assertSee('Profile');
    }

    public function test_page_has_no_password_form(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CustomProfilePage::class)
            ->assertDontSee('Old password')
            ->assertDontSee('wire:submit="save"', false);
    }

    public function test_year_query_switches_the_graph_window(): void
    {
        $user = $this->adminUser();

        // Owner 2026-11-21: per-year only (no rolling mode); default = the
        // current calendar year.
        $this->actingAs($user)->get('/admin/profile')
            ->assertSuccessful()
            ->assertSee('0 incidents in '.now()->year);

        $this->actingAs($user)->get('/admin/profile?year=2025')
            ->assertSuccessful()
            ->assertSee('0 incidents in 2025');

        // Any year is honoured by the mechanism (even a future one).
        $future = now()->year + 3;
        $this->actingAs($user)->get('/admin/profile?year='.$future)
            ->assertSuccessful()
            ->assertSee('0 incidents in '.$future);
    }
}
