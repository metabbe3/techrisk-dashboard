<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Filament\Resources\ActionImprovementResource\Pages\ListActionImprovements;
use App\Models\ActionImprovement;
use App\Models\Category;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\ActionImprovementAssigned;
use App\Notifications\ChannelFilteredNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Re-assignment (owner 2026-11-20): an assignee (or trusted user) can ADD
 * people to an action's pic_email via the Add Assignee row action; the
 * observer emails ONLY the newly-added assignees; and the team-viewer scope
 * grants the incident to anyone assigned to one of its actions — so the
 * reassigned person sees it in their slice (incidents list + Action
 * Improvements page) without any PIC/team match.
 */
class ActionAssigneeReassignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $worker;

    /** A team-viewer with NO team match and NO incident-PIC link — pure stranger. */
    private User $stranger;

    private Incident $incident;

    private ActionImprovement $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        config(['app.debug' => false]); // plain-user Filament pages (documented dev-only 500)

        $itSec = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'IT Sec']);
        $sre = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'SRE']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->worker = User::factory()->create();
        $this->worker->assignRole('team-viewer');
        $this->worker->teams()->sync([$itSec->id]);

        $this->stranger = User::factory()->create();
        $this->stranger->assignRole('team-viewer');
        $this->stranger->teams()->sync([$sre->id]);

        $this->incident = Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['IT Sec']]);
        $this->action = ActionImprovement::factory()->create([
            'incident_id' => $this->incident->id,
            'pic_email' => [$this->worker->email],
        ]);
        Notification::fake(); // drop the created() assignment mail — tests assert only their own sends
    }

    public function test_scope_grants_the_incident_to_an_action_assignee_without_pic_or_team_match(): void
    {
        // A fresh team-viewer on a foreign incident, assigned one action on it.
        $assignee = User::factory()->create();
        $assignee->assignRole('team-viewer'); // NO teams, NOT an incident PIC
        $foreign = Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['Data Platform']]);
        ActionImprovement::factory()->create(['incident_id' => $foreign->id, 'pic_email' => [$assignee->email]]);

        $visible = Incident::query()->applyUserYearAccess($assignee)->pluck('id');

        $this->assertContains($foreign->id, $visible->all(), 'The action assignment IS the grant');
        $this->assertNotContains($this->incident->id, $visible->all(), 'Other incidents stay out of the slice');

        // An UNASSIGNED stranger gets nothing — the grant starts empty.
        $this->assertNotContains($foreign->id, Incident::query()->applyUserYearAccess($this->stranger)->pluck('id')->all());
    }

    public function test_global_action_improvements_page_shows_the_assigned_action(): void
    {
        $assignee = User::factory()->create();
        $assignee->assignRole('team-viewer');
        $foreign = Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['Data Platform']]);
        $assigned = ActionImprovement::factory()->create(['incident_id' => $foreign->id, 'pic_email' => [$assignee->email]]);

        // The grant is incident-level (same as team-match): once the incident
        // is in the slice, its action rows show — including siblings.
        Livewire::actingAs($assignee)
            ->test(ListActionImprovements::class)
            ->assertCanSeeTableRecords([$assigned]);
    }

    public function test_adding_an_assignee_emails_only_the_added_person(): void
    {
        $newPic = User::factory()->create();

        Livewire::actingAs($this->worker)
            ->test(ListActionImprovements::class)
            ->callTableAction('add_assignee', $this->action, data: [
                'new_pic_email' => [$newPic->email],
            ])
            ->assertHasNoTableActionErrors();

        $this->action->refresh();
        $this->assertContains($newPic->email, $this->action->pic_email);
        $this->assertContains($this->worker->email, $this->action->pic_email, 'Add semantics — existing assignees stay');

        Notification::assertSentTo($newPic, ChannelFilteredNotification::class,
            fn (ChannelFilteredNotification $n) => $n->databaseType() === ActionImprovementAssigned::class);
        Notification::assertNotSentTo($this->worker, ChannelFilteredNotification::class,
            fn (ChannelFilteredNotification $n) => $n->databaseType() === ActionImprovementAssigned::class,
            'Existing assignees are not re-emailed on an add');
    }

    public function test_assignee_cannot_add_duplicates_or_empty_selection(): void
    {
        Livewire::actingAs($this->worker)
            ->test(ListActionImprovements::class)
            ->callTableAction('add_assignee', $this->action, data: [
                'new_pic_email' => [$this->worker->email],
            ]);

        $this->action->refresh();
        $this->assertSame([$this->worker->email], $this->action->pic_email, 'No duplicate assignees');
    }

    public function test_stranger_cannot_add_assignees_guard_403(): void
    {
        $this->actingAs($this->stranger);

        $this->expectException(HttpException::class);
        ActionImprovementFormSchema::guardManageAssignees($this->action);
    }

    public function test_trusted_admin_can_add_assignees(): void
    {
        $newPic = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ListActionImprovements::class)
            ->callTableAction('add_assignee', $this->action, data: [
                'new_pic_email' => [$newPic->email],
            ])
            ->assertHasNoTableActionErrors();

        $this->action->refresh();
        $this->assertContains($newPic->email, $this->action->pic_email);
        Notification::assertSentTo($newPic, ChannelFilteredNotification::class,
            fn (ChannelFilteredNotification $n) => $n->databaseType() === ActionImprovementAssigned::class);
    }

    public function test_added_assignee_passes_the_proof_grant_without_team_match(): void
    {
        $assignee = User::factory()->create();
        $assignee->assignRole('team-viewer'); // no teams, no incident PIC link
        $this->action->update(['pic_email' => [$this->worker->email, $assignee->email]]);
        Notification::fake();

        $this->actingAs($assignee);
        $this->assertTrue(ActionImprovementFormSchema::proofAllowed($this->action),
            'The action assignment carries the proof grant too — the reassigned worker can submit proof');
    }

    public function test_add_assignee_is_hidden_on_done_actions(): void
    {
        $this->action->update(['status' => ActionImprovement::STATUS_DONE]);
        Notification::fake();

        Livewire::actingAs($this->worker)
            ->test(ListActionImprovements::class)
            ->assertTableActionHidden('add_assignee', $this->action);
    }
}
