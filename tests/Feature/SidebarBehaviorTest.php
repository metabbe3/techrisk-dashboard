<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner 2026-11-17 (DANA HR reference): the collapsed sidebar temporarily
 * expands as an overlay while the cursor is over it, collapses again on
 * leave, and a pin button on the expanded state makes it permanent. The
 * collapsed rail keeps only the super-menu icons (Filament already hides
 * group children behind the group dropdown when collapsed).
 */
class SidebarBehaviorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The PIN control ships on every panel page (SIDEBAR_NAV_END render
     * hook): server-rendered inside the nav, CSS shows it only while the
     * sidebar is temporarily hover-expanded, and the behaviour script makes
     * its click permanent.
     */
    public function test_the_panel_ships_the_sidebar_pin_control(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSeeHtml('fi-tr-pin-btn', false);
    }

    /**
     * The expand control's icon is a PIN (click = permanent expand), not a
     * plain chevron toggle — overridden via the panels::sidebar.expand-button
     * icon alias (Lucide "pin" path, the only icon rendered from that alias).
     */
    public function test_the_collapsed_sidebar_expand_control_is_a_pin_icon(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSeeHtml('M9 10.76', false);
    }

    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'view dashboard widgets']);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo(['view incidents', 'view dashboard widgets']);

        return $user;
    }
}
