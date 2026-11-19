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

    /**
     * Owner 2026-11-19 (five-point design pass, point 2): when the sidebar
     * is collapsed the rail header shows the DANA LOGO in place of the
     * expand chevron (the topbar carries the text-only brand copy meanwhile
     * — see the topbar-brand test). Filament's x-show hides the logo wrapper
     * with an INLINE display:none — the promotion needs !important to beat
     * it; the 32px img centers in the 4.5rem rail, so the header's px-6
     * (24px < 32px) must go too, and the expand chevron + brand span hide.
     * Source-CSS contract (the pattern is SectionHeaderCornerRadiusTest).
     */
    public function test_the_collapsed_rail_header_shows_the_dana_logo_not_the_chevron(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            ".fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-header > div {\n        display: flex !important;\n        justify-content: center;\n    }",
            $css,
            'the logo wrapper must be promoted over its inline x-show display:none',
        );
        $this->assertStringContainsString(
            ".fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-header {\n        justify-content: center !important;\n        padding-left: 0 !important;\n        padding-right: 0 !important;\n    }",
            $css,
            'the 4.5rem rail header must center the logo, not keep px-6 (24px < the 32px img)',
        );
        $this->assertStringContainsString(
            ".fi-sidebar:not(.fi-sidebar-open) .fi-logo span {\n        display: none !important;\n    }",
            $css,
            'rail shows the logo ONLY — the brand copy lives in the topbar',
        );
        $this->assertStringContainsString(
            ".fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-header .fi-icon-btn {\n        display: none !important;\n    }",
            $css,
            'the expand chevron is REPLACED by the DANA logo — not shown beside it',
        );
    }

    /**
     * Owner 2026-11-19 (temp-expand bug): the pin button is position:fixed
     * over the header's right edge and OVERLAPPED the brand text tail —
     * with its rounded hover fill visible, the button box (starting at
     * 15rem - 2.75rem = 196px) cut into "TechRisk Portal" (ends ≈198.6px
     * at 15rem: 0.75rem logo inset + 32px logo + 0.625rem gap + ~145px
     * nowrap text). The button must start CLEAR of the text tail.
     * Source-CSS contract (the pattern is SectionHeaderCornerRadiusTest).
     */
    public function test_the_temp_open_pin_button_sits_clear_of_the_brand_text(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            'left: calc(15rem - 2.375rem);',
            $css,
            'the pin button must start at 202px — 196px overlaps the ≈198.6px text tail',
        );
        $this->assertStringNotContainsString(
            'left: calc(15rem - 2.75rem);',
            $css,
            'the old 196px offset must be gone',
        );
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
