<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\IncidentResource;
use App\Filament\Resources\IssueResource;
use App\Models\Incident;
use App\Models\User;
use App\Models\UserAuditLogSetting;
use Database\Factories\IncidentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sidebar chrome (2026-11-19 redesign): account + notifications live in the
 * SIDEBAR_FOOTER hook, the command palette replaces the topbar search, and the
 * notifications slide-over is mounted at panels::body.end (NOT inside the
 * <aside> — its transform classes re-anchor position:fixed descendants).
 *
 * Pins:
 * 1. The panel page renders the sidebar search row (palette trigger), the
 *    account row (name + email), the bell with its unread badge, and mounts
 *    BOTH the palette Livewire component and the notifications modal.
 * 2. The bell count is the Filament-format unread notifications count.
 * 3. Guests get the login page cleanly — the BODY_END hook must not crash
 *    for unauthenticated visitors (Filament's component aborts 401 without a
 *    user; the hook view is auth-guarded for exactly that reason).
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

    public function test_panel_page_renders_sidebar_account_bell_and_palette(): void
    {
        $user = $this->panelUser();
        $incident = IncidentFactory::new()->create([
            'classification' => 'Incident',
            'title' => 'Sidebar chrome smoke incident',
        ]);

        $response = $this->actingAs($user)->get('/admin/incidents');

        $response->assertOk();

        $html = $response->getContent();

        // Search row (palette trigger).
        $this->assertStringContainsString('toggle-spotlight', $html);
        $this->assertStringContainsString('sidebar-search-row', $html);

        // Account row: name + email from the authenticated user.
        $this->assertStringContainsString('sidebar-account-user', $html);
        $this->assertStringContainsString(e($user->name), $html);
        $this->assertStringContainsString(e($user->email), $html);

        // Bell row mounted (Livewire renders the component name in its snapshot).
        $this->assertStringContainsString('sidebar-notification-bell', $html);

        // Command palette mounted (plugin renders it at panels::scripts.after).
        $this->assertStringContainsString('LivewireUISpotlight', $html);

        // Notifications modal mounted at body level — the modal id must be on
        // the page for the bell's open-modal dispatch to open anything.
        $this->assertStringContainsString('data-fi-modal-id="database-notifications"', $html);

        // The incident is navigable — record search has something to find.
        $this->assertStringContainsString(e($incident->no), $html);
    }

    public function test_bell_badge_counts_unread_filament_format_notifications(): void
    {
        $user = $this->panelUser();

        $user->notifications()->createMany([
            ['id' => (string) Str::uuid(), 'type' => 'App\Notifications\TestNotification', 'data' => ['format' => 'filament']],
            ['id' => (string) Str::uuid(), 'type' => 'App\Notifications\TestNotification', 'data' => ['format' => 'filament']],
            ['id' => (string) Str::uuid(), 'type' => 'App\Notifications\TestNotification', 'data' => ['format' => 'mail']],
            ['id' => (string) Str::uuid(), 'type' => 'App\Notifications\TestNotification', 'data' => ['format' => 'filament'], 'read_at' => now()],
        ]);

        $response = $this->actingAs($user)->get('/admin/incidents');

        $response->assertOk();

        // 2 unread filament-format only (mail format and read ones excluded).
        $this->assertStringContainsString('sidebar-account-badge', $response->getContent());
        $this->assertStringContainsString('>2</span>', $response->getContent());
    }

    /**
     * Prod 500 after 1–2 minutes idle (BUG, 2026-11-19): the modal component
     * lived in App\Filament\Livewire — outside Livewire's auto-discovery
     * namespace (App\Livewire). The initial render worked (mounted by class),
     * but its 30s wire:poll round-trip resolves the component BY NAME and threw
     * ComponentNotFoundException ("app.filament.livewire.database-notifications")
     * → 500 on every page after the first poll. Pin: every sidebar component's
     * snapshot name must resolve back to its class through the registry —
     * exactly what /livewire/update does.
     */
    public function test_sidebar_livewire_components_resolve_by_name_for_poll_updates(): void
    {
        $registry = app(\Livewire\Mechanisms\ComponentRegistry::class);

        foreach ([\App\Livewire\SidebarDatabaseNotifications::class, \App\Livewire\SidebarNotificationBell::class] as $class) {
            $name = $registry->getName($class);

            // nameToClass() returns a leading-backslash FQCN — normalize.
            $this->assertSame($class, ltrim($registry->getClass($name), '\\'), "Livewire cannot resolve [{$name}] on update requests");
        }
    }

    public function test_account_menu_offers_theme_switcher(): void
    {
        $response = $this->actingAs($this->panelUser())->get('/admin/incidents');

        $response->assertOk();
        $this->assertStringContainsString('fi-theme-switcher', $response->getContent());
        $this->assertStringContainsString('sidebar-account-trigger', $response->getContent());
    }

    public function test_guest_login_page_renders_cleanly(): void
    {
        // The BODY_END hook renders on the login page too (SimplePage shares
        // the base layout); Filament's notifications component aborts 401 for
        // guests, so the hook must stay auth-guarded.
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

        $issue = IncidentFactory::new()->create([
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
