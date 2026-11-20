<?php

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource;
use App\Models\Category;
use App\Models\Incident;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ONE scope (Incident::scopeApplyUserYearAccess) is where the
 * team-viewer branch lives (owner 2026-11-20): a restricted SSO user sees
 * only incidents where they are a PIC or the incident's responsible_team
 * JSON carries one of their mapped teams — empty list otherwise. The match
 * IS the grant: the year filter (UserAuditLogSetting) is bypassed entirely.
 */
class TeamViewerIncidentScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Incident $ownPic;

    private Incident $ownTeam;

    private Incident $both;

    private Incident $otherTeam;

    private Incident $noTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $team = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'IT Sec']);
        $other = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'SRE']);

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('team-viewer');
        $this->viewer->teams()->sync([$team->id]);

        $this->ownPic = Incident::factory()->create(['classification' => 'Incident']);
        $this->ownPic->pics()->attach($this->viewer->id);

        $this->ownTeam = Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['IT Sec']]);
        $this->both = Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['IT Sec', 'SRE']]);
        $this->both->pics()->attach($this->viewer->id);

        $this->otherTeam = Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['SRE']]);
        $this->noTeam = Incident::factory()->create(['classification' => 'Incident']);
    }

    private function visibleIds(User $user): array
    {
        return Incident::query()->applyUserYearAccess($user)->pluck('id')->all();
    }

    public function test_viewer_sees_own_pic_and_team_incidents_and_nothing_else(): void
    {
        $visible = $this->visibleIds($this->viewer);

        $this->assertContains($this->ownPic->id, $visible, 'Own PIC assignment must grant visibility');
        $this->assertContains($this->ownTeam->id, $visible, 'Team match via responsible_team JSON must grant visibility');
        $this->assertContains($this->both->id, $visible);
        $this->assertNotContains($this->otherTeam->id, $visible, 'Another team incident must stay invisible');
        $this->assertNotContains($this->noTeam->id, $visible, 'Unassigned incident must stay invisible');
        $this->assertCount(3, $visible);
    }

    public function test_viewer_with_no_teams_and_no_pics_sees_nothing(): void
    {
        $nobody = User::factory()->create();
        $nobody->assignRole('team-viewer');

        $this->assertSame([], $this->visibleIds($nobody), 'The restricted list must start EMPTY');
    }

    public function test_team_match_bypasses_the_year_filter(): void
    {
        // The team/PIC match IS the grant — an old-year incident their team
        // owns must still be visible even though UserAuditLogSetting would
        // year-restrict a normal user to the current year.
        $old = Incident::factory()->create([
            'classification' => 'Incident',
            'responsible_team' => ['IT Sec'],
            'incident_date' => now()->subYears(2)->startOfYear(),
        ]);

        $this->assertContains($old->id, $this->visibleIds($this->viewer));
    }

    public function test_resource_wrapper_and_record_gate_agree_with_the_scope(): void
    {
        $this->actingAs($this->viewer);

        // Incidents table + global search + kanban all route through the
        // resource wrapper (classification != Issue + the shared scope).
        $query = IncidentResource::applyAccessControl(Incident::query());
        $visible = $query->pluck('id')->all();

        $this->assertEquals($this->visibleIds($this->viewer), $visible, 'Wrapper must not drift from the scope');

        $this->assertTrue(IncidentResource::canView($this->ownPic));
        $this->assertTrue(IncidentResource::canView($this->ownTeam));
        $this->assertFalse(IncidentResource::canView($this->otherTeam), 'Deep link to an out-of-scope incident must 403');
        $this->assertFalse(IncidentResource::canView($this->noTeam));

        // Guests are a no, never a 500 (Spotlight evaluates gates logged out).
        auth()->logout();
        $this->assertFalse(IncidentResource::canView($this->ownPic));
    }

    public function test_admin_and_user_roles_are_unaffected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->assertCount(5, $this->visibleIds($admin), 'Admin must still see everything');

        $plain = User::factory()->create();
        $plain->assignRole('user');
        $this->assertCount(5, $this->visibleIds($plain), 'The normal user role must be untouched');

        $this->assertFalse($plain->isTeamViewer());
    }

    public function test_granting_a_normal_role_on_top_of_team_viewer_lifts_the_restriction(): void
    {
        // Owner 2026-11-20: "if i give them role like manager / user they can
        // see all incident like normal role" — team-viewer is the fallback
        // for the ROLE-LESS, not an additive cap.
        $this->viewer->assignRole('user');

        $this->assertFalse($this->viewer->isTeamViewer());
        $this->assertCount(5, $this->visibleIds($this->viewer->fresh()),
            'The normal-role visibility must replace the restricted slice');
    }
}
