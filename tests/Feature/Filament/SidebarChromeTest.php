<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\IncidentResource;
use App\Filament\Resources\IssueResource;
use App\Models\Incident;
use App\Models\User;
use App\Models\UserAuditLogSetting;
use Database\Factories\IncidentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sidebar chrome (2026-11-19, owner round 2): profile + notifications moved
 * BACK to the topbar — the SIDEBAR_FOOTER hook, the custom bell
 * (App\Livewire\SidebarNotificationBell) and the trigger-free modal
 * (App\Livewire\SidebarDatabaseNotifications) are deleted, and Filament's
 * default database-notifications bell + user menu serve the topbar. The
 * theme switcher lives inside the user menu (round 3: the standalone
 * topbar hook is a duplicate of Filament's built-in dropdown switcher).
 * The sidebar keeps the search row (palette trigger).
 *
 * Pins:
 * 1. The panel page renders the sidebar search row (palette trigger), the
 *    topbar bell (open-modal dispatch + database-notifications modal at body
 *    level), and the topbar user menu — and renders NO sidebar footer.
 * 2. The theme switcher renders INSIDE the user menu (owner round 3 killed
 *    the standalone topbar hook — Filament's default dropdown switcher is
 *    the one).
 * 3. Guests get the login page cleanly.
 * 4. Palette record search is access-scoped: IncidentResource global search
 *    runs through applyAccessControl (year access + non-Issue), so a
 *    year-restricted user cannot find other years' incidents via Cmd+K.
 */
class SidebarChromeTest extends TestCase
{
    use RefreshDatabase;

    private function panelUser(): User
    {
        foreach (['access dashboard', 'view incidents'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $user = User::factory()->create();
        $user->givePermissionTo(['access dashboard', 'view incidents']);

        return $user;
    }

    public function test_panel_page_renders_topbar_bell_and_search_row_not_sidebar_footer(): void
    {
        $user = $this->panelUser();
        $incident = IncidentFactory::new()->create([
            'classification' => 'Incident',
            'title' => 'Sidebar chrome smoke incident',
        ]);

        $response = $this->actingAs($user)->get('/admin/incidents');

        $response->assertOk();

        $html = $response->getContent();

        // Command palette deleted (owner 2026-11-20): no trigger row, no
        // spotlight component anywhere on the page.
        $this->assertStringNotContainsString('toggle-spotlight', $html);
        $this->assertStringNotContainsString('LivewireUISpotlight', $html);

        // Topbar bell: Filament's default DatabaseNotifications component
        // mounts in the topbar (Filament's own topbar/index.blade.php renders
        // it between the search and the user menu). It is LAZY — the initial
        // HTML carries only the Livewire snapshot; the trigger + modal
        // hydrate client-side. The panel flag itself defaults to FALSE, so
        // the provider must call databaseNotifications() explicitly.
        $this->assertStringContainsString('&quot;name&quot;:&quot;filament.livewire.database-notifications&quot;', $html);
        $this->assertStringContainsString(
            '->databaseNotifications()',
            (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php')),
            'the panel flag defaults false — the bell only mounts when databaseNotifications() is called',
        );

        // Topbar account control (avatar + name, see TopbarWidgetsTest).
        $this->assertStringContainsString('fi-user-menu', $html);
        $this->assertStringContainsString(e($user->name), $html);

        // The sidebar footer is GONE — no account row, no custom bell, no
        // trigger row, no dead footer container.
        $this->assertStringNotContainsString('fi-sidebar-footer', $html);
        $this->assertStringNotContainsString('sidebar-account-user', $html);
        $this->assertStringNotContainsString('sidebar-notification-bell', $html);
        $this->assertStringNotContainsString('sidebar-account-trigger', $html);

        // Notifications modal mounted at body level — the modal id must be on
        // the page for the bell's open-modal dispatch to open anything.
        $this->assertStringContainsString('data-fi-modal-id="database-notifications"', $html);

        // The incident is navigable — record search has something to find.
        $this->assertStringContainsString(e($incident->no), $html);
    }

    /**
     * Owner 2026-11-19: the light/dark toggle lives INSIDE the profile
     * (user menu) button — Filament's default user-menu already renders
     * <x-filament-panels::theme-switcher /> inside its dropdown (gated on
     * hasDarkMode, active by default). The round-2 USER_MENU_BEFORE hook
     * was a duplicate: it drew the same switcher as a standalone 3-icon
     * row in the topbar. It must be gone; the switcher then renders only
     * inside the menu, AFTER the menu's own markup starts.
     */
    public function test_the_theme_switcher_lives_inside_the_user_menu(): void
    {
        $provider = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $this->assertStringNotContainsString('PanelsRenderHook::USER_MENU_BEFORE', $provider, 'the standalone topbar switcher hook is a duplicate — the user menu carries its own');

        $response = $this->actingAs($this->panelUser())->get('/admin/incidents');
        $response->assertOk();
        $html = (string) $response->getContent();
        $this->assertStringContainsString('fi-theme-switcher', $html, "the switcher must still render — inside the user menu's dropdown");
        $this->assertGreaterThan(
            mb_strpos($html, 'fi-user-menu'),
            mb_strpos($html, 'fi-theme-switcher'),
            'the switcher must render inside (after the start of) the user-menu dropdown, not the topbar row before it',
        );
    }

    /**
     * Owner 2026-11-19: "perbesar page dashboardnya, di kanan-kiri masih
     * ada ruang" — Filament caps fi-main at max-w-7xl (1280px) unless the
     * panel sets maxContentWidth; on a 1920 viewport that left ~200px of
     * dead margin each side. The panel goes Full (fi-main keeps its own
     * px-4/6/8 gutters).
     */
    public function test_the_panel_content_spans_the_full_width(): void
    {
        $response = $this->actingAs($this->panelUser())->get('/admin/incidents');
        $response->assertOk();
        $html = (string) $response->getContent();

        preg_match('/<main[^>]*fi-main[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, 'the fi-main element must render');
        $this->assertStringContainsString('max-w-full', $m[0], 'the content column must span the full width');
        $this->assertStringNotContainsString('max-w-7xl', $m[0], 'the 1280px default cap must be gone');
    }

    public function test_guest_login_page_renders_cleanly(): void
    {
        $this->get('/admin/login')->assertOk();

        $this->assertGuest();

        $this->get('/admin/incidents')->assertRedirect('/admin/login');
    }

    public function test_palette_record_search_respects_year_access(): void
    {
        $user = $this->panelUser();

        UserAuditLogSetting::create([
            'user_id' => $user->id,
            'allowed_years' => [2025],
            'can_view_all_logs' => false,
        ]);

        $reachable = IncidentFactory::new()->create([
            'no' => '2025_IN_P1_001',
            'title' => 'Database timeout reachable',
            'classification' => 'Incident',
            'incident_date' => '2025-06-15 10:00:00',
        ]);

        IncidentFactory::new()->create([
            'no' => '2026_IN_P1_002',
            'title' => 'Database timeout forbidden year',
            'classification' => 'Incident',
            'incident_date' => '2026-03-10 08:00:00',
        ]);

        IncidentFactory::new()->create([
            'no' => '2025_IN_P1_003',
            'title' => 'Database timeout wrong classification',
            'classification' => 'Issue',
            'incident_date' => '2025-07-01 09:00:00',
        ]);

        $this->actingAs($user);

        $results = IncidentResource::getGlobalSearchResults('timeout');

        $this->assertCount(1, $results);
        $this->assertStringContainsString($reachable->no, (string) $results->first()->title);
        $this->assertStringContainsString($reachable->id, $results->first()->url);
    }

    public function test_palette_record_search_returns_issue_scoped_results(): void
    {
        $user = $this->panelUser();

        // Full year access — this test isolates the Issue classification scope.
        UserAuditLogSetting::create([
            'user_id' => $user->id,
            'allowed_years' => [2025, 2026],
            'can_view_all_logs' => true,
        ]);

        $issue = IncidentFactory::new()->create([
            'no' => '2025_IN_P1_010',
            'title' => 'Kanban blocker issue',
            'classification' => 'Issue',
            'incident_date' => '2025-05-05 05:00:00',
        ]);

        IncidentFactory::new()->create([
            'no' => '2025_IN_P1_011',
            'title' => 'Kanban blocker incident',
            'classification' => 'Incident',
            'incident_date' => '2025-05-05 06:00:00',
        ]);

        $this->actingAs($user);

        $results = IssueResource::getGlobalSearchResults('blocker');

        $this->assertCount(1, $results);
        $this->assertStringContainsString($issue->no, (string) $results->first()->title);
    }
}
