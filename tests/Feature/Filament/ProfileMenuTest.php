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

        $order = [
            'tr-user-menu-head',
            'Your active incident',
            'admin/profile"',   // the stock "Profile" item links here
            'admin/setting"',   // the new Setting page (change password)
            'tr-theme-expander',
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

    public function test_appearance_expander_carries_three_cards_and_the_theme_bridge(): void
    {
        $html = $this->panelHtml();

        $this->assertStringContainsString('tr-theme-card', $html);
        foreach (['tr-theme-preview-light', 'tr-theme-preview-dark', 'tr-theme-preview-system'] as $preview) {
            $this->assertStringContainsString($preview, $html, "missing preview card: {$preview}");
        }
        $this->assertStringContainsString("new CustomEvent('theme-changed'", $html);
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

        // Info block + graph + year pills (rolling default selected).
        $this->assertStringContainsString('tr-profile-info-name', $html);
        $this->assertStringContainsString(e($this->user->email), $html);
        $this->assertStringContainsString('tr-contrib-cells', $html);
        $this->assertStringContainsString('tr-contrib-years', $html);
        $this->assertStringContainsString('12 months', $html);
        $this->assertStringContainsString('tr-contrib-year-active', $html);

        // No password form on this page any more.
        $this->assertStringNotContainsString('wire:submit="save"', $html);
        $this->assertStringNotContainsString('data.current_password', $html);
    }

    public function test_source_css_carries_the_revamp_contracts(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString('.fi-topbar .fi-user-menu .fi-dropdown-list:has(> .fi-theme-switcher)', $css, 'the stock icon switcher must be hidden while its markup stays');
        $this->assertStringContainsString('.fi-resource-relation-managers > .fi-tabs .fi-tabs-item.fi-active', $css, 'incident detail tab strips adopt the underline look');
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
