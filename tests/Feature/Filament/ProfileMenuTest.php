<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The GitHub-style profile revamp (owner 2026-11-20 + revisi): dropdown order
 * (header, active-incidents count, Profile, Appearance, Sign out — "My
 * Incident" removed, the count row already links to the same list), the
 * count's deep link, the Appearance expander markup + theme bridge, the
 * profile page (top nav strip, graph, change-password form behind a button)
 * and the source-CSS contracts.
 */
class ProfileMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        foreach (['view incidents', 'view dashboard widgets'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        $this->user->givePermissionTo(['view incidents', 'view dashboard widgets']);
    }

    private function panelHtml(): string
    {
        return (string) $this->actingAs($this->user)
            ->get('/admin')
            ->assertSuccessful()
            ->getContent();
    }

    public function test_dropdown_renders_header_count_and_items_in_order(): void
    {
        $html = $this->panelHtml();

        $this->assertStringContainsString('tr-user-menu-head', $html);
        $this->assertStringContainsString('Your active incident', $html);
        $this->assertStringContainsString('tr-theme-expander', $html);
        $this->assertStringContainsString('Appearance', $html);
        $this->assertStringContainsString('Setting', $html);
        $this->assertStringNotContainsString('My Incident', $html, 'owner revisi: the count row replaces the My Incident item');

        // Owner 2026-11-21: Setting sits directly ABOVE Sign out.
        $order = [
            'tr-user-menu-head',
            'Your active incident',
            'admin/profile"',   // the stock "Profile" item links here
            'tr-theme-expander',
            'admin/setting"',   // the Setting page (change password)
            'Sign out',
        ];
        $last = -1;
        foreach ($order as $needle) {
            $position = mb_strpos($html, $needle);
            $this->assertNotFalse($position, "missing: {$needle}");
            $this->assertGreaterThan($last, $position, "out of order: {$needle}");
            $last = $position;
        }
    }

    public function test_count_item_links_to_the_pic_filtered_list(): void
    {
        $html = $this->panelHtml();

        // route() serialises the values array with its numeric key.
        $expected = 'tableFilters%5Bpic%5D%5Bvalues%5D%5B0%5D='.$this->user->id;
        $this->assertSame(1, substr_count($html, $expected), 'the count item is the ONE link to the pic-filtered list');
    }

    public function test_appearance_expander_is_three_icon_buttons(): void
    {
        // Owner 2026-11-21: icons only — the preview cards are gone.
        $html = $this->panelHtml();

        $this->assertStringContainsString('tr-theme-icons', $html);
        $this->assertSame(3, substr_count($html, 'tr-theme-icon-btn'), 'light, dark and system icon buttons');
        $this->assertStringContainsString("new CustomEvent('theme-changed'", $html);
        $this->assertStringNotContainsString('tr-theme-preview', $html, 'no preview mockups any more');
        $this->assertStringNotContainsString('tr-theme-card', $html, 'no preview cards any more');
    }

    public function test_profile_page_renders_info_graph_and_year_pills(): void
    {
        $html = (string) $this->actingAs($this->user)
            ->get('/admin/profile')
            ->assertSuccessful()
            ->getContent();

        // Owner revisi 3: the single-item top nav strip was DROPPED; the
        // change-password form moved to the Setting page.
        $this->assertStringNotContainsString('tr-profile-tabs', $html);
        $this->assertStringNotContainsString('tr-profile-land', $html);
        $this->assertStringNotContainsString('My Incident', $html);

        // Info block + graph + year pills (owner 2026-11-21: per-year only —
        // no "12 months" rolling option; the current year starts selected).
        $this->assertStringContainsString('tr-profile-info-name', $html);
        $this->assertStringContainsString(e($this->user->email), $html);
        $this->assertStringContainsString('tr-contrib-cells', $html);
        $this->assertStringContainsString('tr-contrib-years', $html);
        $this->assertStringNotContainsString('12 months', $html);
        $this->assertStringContainsString('tr-contrib-year-active', $html);
        $this->assertStringContainsString((string) now()->year, $html);
        $this->assertStringContainsString('incidents in '.now()->year, $html);
        // Owner 2026-11-23: per-tier breakdown next to the total.
        $this->assertStringContainsString('In progress', $html);
        $this->assertStringContainsString('Monitoring actions', $html);
        $this->assertStringContainsString('Completed', $html);
        $this->assertStringContainsString('tr-graph-stats', $html);

        // No password form on this page any more.
        $this->assertStringNotContainsString('wire:submit="save"', $html);
        $this->assertStringNotContainsString('data.current_password', $html);
    }

    public function test_source_css_carries_the_revamp_contracts(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString('.fi-topbar .fi-user-menu .fi-dropdown-list:has(> .fi-theme-switcher)', $css, 'the stock icon switcher must be hidden while its markup stays');
        $this->assertStringContainsString('.fi-resource-relation-managers > .fi-tabs .fi-tabs-item.fi-active', $css, 'incident detail tab strips adopt the underline look');
        $this->assertStringContainsString('.fi-resource-incidents.fi-resource-list-records-page .fi-tabs', $css, 'incident LIST tabs adopt the underline look');
        $this->assertStringContainsString('.fi-tabs:hover::-webkit-scrollbar', $css, 'the list-tab scrollbar shows only on hover');
        $this->assertStringContainsString('.fi-page-sub-navigation-tabs.fi-tabs', $css, 'cluster tabs adopt the same underline + hover-scroll model');
        $this->assertStringContainsString('.tr-vscroll-thumb', $css, 'the overlay page scrollbar rail');
        $this->assertStringContainsString('.tr-scroller', $css, 'inner scrollers share the adaptive mechanism');
        // ⚠ the native root bar is hidden with ZERO width (no reserved gutter →
        // no gap at the page edge); the overlay rail owns the visuals.
        $this->assertStringContainsString("html::-webkit-scrollbar {\n    width: 0;", $css, 'native root scrollbar hidden without a gutter');
        $this->assertStringContainsString('.tr-theme-icon-btn', $css, 'appearance icon buttons');
        $this->assertStringNotContainsString('.tr-theme-preview', $css, 'appearance previews removed');
        $this->assertStringContainsString('--tr-contrib-tier1:', $css, 'contribution graph tier tokens');

        // ⚠ Filament serves --gray-* as RAW RGB TRIPLETS — var() on them is
        // invalid and inherits (the light-mode invisibility bug). The tr-*
        // block must never reference them.
        $this->assertStringNotContainsString('var(--gray-', $this->withoutComments($css), 'raw-RGB triplet variables are invalid in color: — use hex');
        $this->assertStringNotContainsString('body:has(.tr-profile-land)', $css, 'the profile-land shell was dropped (owner revisi)');
    }

    /** Strip CSS comments so the documentation line mentioning the pitfall does not trip the ban. */
    private function withoutComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }
}
