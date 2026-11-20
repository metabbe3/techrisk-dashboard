<?php

namespace Tests\Feature;

use App\Filament\Resources\ActionImprovementResource\Pages\EditActionImprovement;
use App\Models\ActionImprovement;
use App\Models\Category;
use App\Models\Incident;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Navigation + page-gate audit for the restricted SSO role (owner
 * 2026-11-20): team-viewers land on the incidents list ONLY. Reporting and
 * the weekly-report export aggregate org-wide past their slice — both must
 * 403. Action Improvements stays visible (the proof surface) but rows are
 * scoped and the edit page is denied.
 */
class TeamViewerNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Incident $ownTeamIncident;

    private Incident $foreignIncident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['app.debug' => false]); // Livewire dev-only multi-root 500 for non-admins otherwise
        // Creating actions notifies PICs — without fake(), the "assigned to
        // you" flash appears in the page chrome and defeats assertDontSee.
        Notification::fake();

        $team = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'IT Sec']);

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('team-viewer');
        $this->viewer->teams()->sync([$team->id]);

        $this->ownTeamIncident = Incident::factory()->create([
            'classification' => 'Incident',
            'title' => 'Viewer visible incident',
            'responsible_team' => ['IT Sec'],
        ]);
        $this->foreignIncident = Incident::factory()->create([
            'classification' => 'Incident',
            'title' => 'Viewer invisible incident',
            'responsible_team' => ['SRE'],
        ]);
    }

    private function viewer(): static
    {
        return $this->actingAs($this->viewer->fresh());
    }

    public function test_incidents_list_shows_only_the_own_slice(): void
    {
        $this->viewer()
            ->get('/admin/incidents')
            ->assertOk()
            ->assertSee('Viewer visible incident')
            ->assertDontSee('Viewer invisible incident');
    }

    public function test_issues_and_other_surfaces_stay_hidden_from_the_incidents_page(): void
    {
        $html = $this->viewer()->get('/admin/incidents')->getContent();

        $this->assertStringNotContainsString('/admin/issues', $html, 'No view issues permission — Issues tab must not render');
    }

    public function test_reporting_page_and_weekly_export_are_denied(): void
    {
        $this->viewer()->get('/admin/reporting')->assertForbidden();
        $this->viewer()->get('/admin/weekly-report/export/'.now()->year)->assertForbidden();
    }

    public function test_panel_root_redirects_to_the_incidents_list_not_a_dead_403(): void
    {
        // The 403 the owner hit live (2026-11-20): /admin serves the
        // Dashboard, which team-viewers cannot access — redirect to their
        // panel home instead (RedirectToPanelHome middleware).
        $this->viewer()
            ->get('/admin')
            ->assertRedirect(route('filament.admin.resources.incidents.index'));
    }

    public function test_action_improvements_page_lists_only_the_own_slice(): void
    {
        ActionImprovement::factory()->create([
            'incident_id' => $this->ownTeamIncident->id,
            'title' => 'Viewer proof action',
            'pic_email' => [$this->viewer->email],
        ]);
        ActionImprovement::factory()->create([
            'incident_id' => $this->foreignIncident->id,
            'title' => 'Foreign action',
            'pic_email' => [$this->viewer->email],
        ]);

        $this->viewer()
            ->get('/admin/action-improvements')
            ->assertOk()
            ->assertSee('Viewer proof action')
            ->assertDontSee('Foreign action');
    }

    public function test_incident_and_action_edit_pages_are_denied(): void
    {
        $action = ActionImprovement::factory()->create([
            'incident_id' => $this->ownTeamIncident->id,
            'pic_email' => [$this->viewer->email],
        ]);

        // canCreate / policy update = manage incidents.
        $this->viewer()->get('/admin/incidents/create')->assertForbidden();
        $this->viewer()->get(EditActionImprovement::getUrl(['record' => $action]))->assertForbidden();
    }
}
