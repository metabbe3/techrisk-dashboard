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
     * Owner 2026-11-18: when the sidebar is collapsed the DANA logo lives in
     * the rail header (CSS promotes the existing brand wrapper there) — the
     * topbar brand copy is TEXT ONLY, no second logo beside the rail's.
     */
    public function test_the_topbar_brand_copy_carries_no_logo_image(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->get('/admin');
        $response->assertSuccessful()
            ->assertSeeHtml('fi-topbar-brand', false);

        preg_match('/<a[^>]*fi-topbar-brand.*?<\/a>/s', (string) $response->getContent(), $m);
        $this->assertNotEmpty($m, 'the topbar brand anchor must render');
        $this->assertStringNotContainsString('<img', $m[0]);
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
