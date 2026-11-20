<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The restricted SSO role (owner 2026-11-20): exactly three permissions —
 * view incidents (the slice), access dashboard (panel entry ONLY — the
 * Dashboard page itself stays hidden), submit action proof.
 */
class TeamViewerRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_role_with_exactly_three_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $role = Role::findByName('team-viewer');
        $this->assertNotNull($role);

        $this->assertEqualsCanonicalizing(
            ['view incidents', 'access dashboard', 'submit action proof'],
            $role->permissions->pluck('name')->all(),
            'The restricted role must not silently gain permissions',
        );

        // Idempotent re-seed must not duplicate anything.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertCount(3, $role->fresh()->permissions);
    }

    public function test_viewer_passes_the_panel_gate_but_never_the_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $viewer = User::factory()->create();
        $viewer->assignRole('team-viewer');
        $viewer = $viewer->fresh();

        $this->assertTrue($viewer->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
        $this->assertFalse($viewer->canViewDashboardWidgets(), 'Dashboard page + widgets must stay hidden');
        $this->assertTrue($viewer->can('submit action proof'));
        $this->assertFalse($viewer->can('manage incidents'), 'View-only: no create/edit/delete/export');
        $this->assertFalse($viewer->can('view issues'));
        $this->assertFalse($viewer->can('access api'), 'No API for the restricted role');
        $this->assertTrue($viewer->isTeamViewer());
    }

    public function test_locked_directory_row_is_still_denied_the_panel(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $locked = User::factory()->create(['can_login' => false]);
        $locked->assignRole('team-viewer');
        $locked = $locked->fresh();

        $this->assertFalse($locked->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')),
            'can_login=false revocation must survive the team-viewer role');
    }
}
