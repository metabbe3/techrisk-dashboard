<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Database\Factories\IncidentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sidebar consolidation: same-meaning groups became Filament clusters —
 * one sidebar item per cluster, members as top tabs (SubNavigationPosition::Top).
 *
 * Pins:
 * 1. A cluster member renders the sub-navigation tabs and the sidebar shows
 *    ONE cluster item (the old per-resource group headers are gone).
 * 2. Per-user gating survives: a user without `manage api tokens` sees no
 *    Agents tab, but the AI cluster itself stays visible (AiChat is ungated).
 * 3. A cluster whose every member is gated away (Logs for a plain user)
 *    disappears from the sidebar entirely.
 */
class ClusterNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function plainUser(): User
    {
        foreach (['access dashboard', 'view incidents', 'access ai chat'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $user = User::factory()->create();
        $user->givePermissionTo(['access dashboard', 'view incidents', 'access ai chat']);

        return $user;
    }

    private function adminUser(): User
    {
        $user = $this->plainUser();
        // Both Logs-cluster permissions: AiUsageLog gates on view ai usage logs,
        // ApiAuditLog on view audit logs (each = admin OR its permission).
        foreach (['manage api tokens', 'view ai usage logs', 'view audit logs'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $user->givePermissionTo(['manage api tokens', 'view ai usage logs', 'view audit logs']);

        return $user;
    }

    public function test_cluster_member_renders_top_sub_navigation_and_single_sidebar_item(): void
    {
        IncidentFactory::new()->create(['classification' => 'Incident']);

        $html = $this->actingAs($this->adminUser())->get('/admin/logs/ai-usage-logs')->getContent();

        // Members render as tabs on the page, and the sidebar links the
        // cluster-prefixed URL (one item — the old group header is gone).
        $this->assertStringContainsString('/admin/logs/ai-usage-logs', $html);
        $this->assertStringContainsString('/admin/logs/api-audit-logs', $html);
    }

    public function test_gated_ai_tabs_hidden_without_manage_api_tokens(): void
    {
        IncidentFactory::new()->create(['classification' => 'Incident']);
        // app.debug=true trips Livewire's dev-only multi-root detection on this
        // layout for non-admin users (pre-existing: Dashboard throws the same
        // way). Cluster tabs render identically with the check off.
        config(['app.debug' => false]);

        $html = $this->actingAs($this->plainUser())->get('/admin/ai/ai-chat')->getContent();

        // Ungated anchor pages keep the AI cluster alive...
        $this->assertStringContainsString('TechRisk AI', $html);
        // ...but the gated resources are not offered as tabs.
        $this->assertStringNotContainsString('/admin/ai/ai-agents', $html);
    }

    public function test_gated_ai_tabs_visible_with_manage_api_tokens(): void
    {
        IncidentFactory::new()->create(['classification' => 'Incident']);
        config(['app.debug' => false]);

        $html = $this->actingAs($this->adminUser())->get('/admin/ai/ai-chat')->getContent();

        $this->assertStringContainsString('/admin/ai/ai-agents', $html);
    }

    public function test_logs_cluster_disappears_for_users_without_log_permission(): void
    {
        IncidentFactory::new()->create(['classification' => 'Incident']);

        $html = $this->actingAs($this->plainUser())->get('/admin/incidents')->getContent();

        $this->assertStringNotContainsString('/admin/logs/ai-usage-logs', $html);
    }
}
