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
     * nowrap text). The button must start CLEAR of the text tail — now at
     * the round-3 sidebar width 16.5rem (owner: widen so nothing truncates).
     * Source-CSS contract (the pattern is SectionHeaderCornerRadiusTest).
     */
    public function test_the_temp_open_pin_button_sits_clear_of_the_brand_text(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            'left: calc(16.5rem - 2.375rem);',
            $css,
            'the pin button must start clear of the ≈198.6px text tail at the 16.5rem width',
        );
        $this->assertStringNotContainsString(
            'left: calc(15rem - 2.375rem);',
            $css,
            'the 15rem-era offset must be gone',
        );
    }

    /**
     * Owner 2026-11-19: "font di side bar bisa lebih besar" restored the
     * pre-main sizes, "ubah size menjadi di tengah-tengah" midpointed them
     * (label 0.9rem/1.25rem, icons 1.04rem, group 0.715rem), then "perbesar
     * sedikit lagi, yang sekarang 0.9rem jadi 0.95rem, dan sesuaikan yang
     * lainnya": the label goes to 0.95rem (line-height scaled to 1.3rem) and
     * the sibling sidebar type scales with it — icons 1.04 → 1.1rem, group
     * label 0.715 → 0.75rem. AND the open sidebar widens 15rem → 16.5rem so
     * nothing ellipsizes at the bigger label — the pin button (fixed over
     * the header's right edge, offset tied to the width) and the
     * temp-expand overlay follow the same constant.
     * Source-CSS contract (the pattern is SectionHeaderCornerRadiusTest).
     */
    public function test_sidebar_nav_typography_and_width(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            ".fi-sidebar-item-label {\n    font-size: 0.95rem !important;\n    line-height: 1.3rem !important;",
            $css,
            'nav item labels: owner bump 0.9 → 0.95rem (line-height scaled 1.25 → 1.3rem)',
        );
        $this->assertStringContainsString(
            ".fi-sidebar-item .fi-icon,\n.fi-sidebar-item-icon {\n    width: 1.1rem !important;\n    height: 1.1rem !important;",
            $css,
            'nav icons scale with the label: 1.04 → 1.1rem',
        );
        $this->assertStringContainsString(
            ".fi-sidebar-group-label {\n    font-size: 0.75rem !important;",
            $css,
            'group section markers scale with the label: 0.715 → 0.75rem',
        );

        // Width: open sidebar 15rem → 16.5rem (264px) so the bigger labels
        // never ellipsize; every 15rem-dependent constant moves together.
        $this->assertStringContainsString(
            ".fi-sidebar.fi-sidebar-open {\n    width: 16.5rem !important;\n}",
            $css,
            'open sidebar width: 16.5rem, wider than the old 15rem',
        );
        $this->assertStringNotContainsString('width: 15rem !important;', $css, 'the old 15rem open width must be gone');
        $this->assertStringContainsString(
            'left: calc(16.5rem - 2.375rem);',
            $css,
            'the pin button offset follows the new width — must start clear of the brand text tail',
        );
        $this->assertStringNotContainsString('left: calc(15rem - 2.375rem);', $css, 'the old pin offset must be gone');
        $this->assertStringContainsString(
            'margin-inline-end: calc(4.5rem - 16.5rem);',
            $css,
            'the temp-expand overlay margin follows the new width (page content never moves)',
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
