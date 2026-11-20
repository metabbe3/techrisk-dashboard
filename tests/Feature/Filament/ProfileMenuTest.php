<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The GitHub-style profile revamp (owner 2026-11-20): dropdown order (header,
 * active-incidents count, Profile, My Incident, Appearance, Sign out), the
 * count's deep link, the Appearance expander markup + theme bridge, the
 * profile page's shell (right nav, graph, VERBATIM password form) and the
 * source-CSS contracts for the profile-land sidebar rules.
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
        $this->assertStringContainsString('My Incident', $html);
        $this->assertStringContainsString('tr-theme-expander', $html);
        $this->assertStringContainsString('Appearance', $html);

        $order = [
            'tr-user-menu-head',
            'Your active incident',
            'admin/profile"',   // the stock "Profile" item links here
            'My Incident',
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

    public function test_count_item_and_my_incident_link_to_the_pic_filtered_list(): void
    {
        $html = $this->panelHtml();

        // route() serialises the values array with its numeric key.
        $expected = 'tableFilters%5Bpic%5D%5Bvalues%5D%5B0%5D='.$this->user->id;
        $this->assertSame(2, substr_count($html, $expected), 'both count item and My Incident link to the pic-filtered list');
    }

    public function test_appearance_expander_carries_three_cards_and_the_theme_bridge(): void
    {
        $html = $this->panelHtml();

        $this->assertStringContainsString('tr-theme-card', $html);
        foreach (['tr-theme-preview-light', 'tr-theme-preview-dark', 'tr-theme-preview-system'] as $preview) {
            $this->assertStringContainsString($preview, $html, "missing preview card: {$preview}");
        }
        $this->assertStringContainsString("new CustomEvent('theme-changed'", $html);
        $this->assertStringContainsString('tr-open-appearance', $html);
        $this->assertStringContainsString('__trAppearanceBridge', $html);
    }

    public function test_profile_page_renders_shell_nav_graph_and_the_password_form(): void
    {
        $html = (string) $this->actingAs($this->user)
            ->get('/admin/profile')
            ->assertSuccessful()
            ->getContent();

        // Info block + right nav (Profile active, My Incident deep link,
        // Appearance bridge button).
        $this->assertStringContainsString('tr-profile-land', $html);
        $this->assertStringContainsString('tr-profile-info-name', $html);
        $this->assertStringContainsString(e($this->user->email), $html);
        $this->assertStringContainsString('tr-profile-nav', $html);
        $this->assertStringContainsString('tr-profile-nav-active', $html);
        $this->assertStringContainsString('tr-contrib-cells', $html);

        // The VERBATIM vendor form block — the save() pipeline pin.
        $this->assertStringContainsString('wire:submit="save"', $html);
        foreach (['data.name', 'data.email', 'data.password', 'data.password_confirmation'] as $field) {
            $this->assertStringContainsString($field, $html, "missing form field: {$field}");
        }

        // Profile-land: the one-shot sidebar close script.
        $this->assertStringContainsString('__trProfileLandSidebar', $html);
    }

    public function test_source_css_carries_the_revamp_contracts(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString('.fi-topbar .fi-user-menu .fi-dropdown-list:has(> .fi-theme-switcher)', $css, 'the stock icon switcher must be hidden while its markup stays');
        $this->assertStringContainsString('body:has(.tr-profile-land) .fi-sidebar {', $css, 'profile-land sidebar overlay rules');
        $this->assertStringContainsString('body:has(.tr-profile-land) .fi-tr-burger', $css, 'the hamburger shows only in profile-land');
        $this->assertStringContainsString('.tr-sidebar-close-btn', $css, 'the ✕ close button styles');
        $this->assertStringContainsString('--tr-contrib-tier1:', $css, 'contribution graph tier tokens');
    }
}
