<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Filament\Resources\ActionImprovementResource\Pages\ListActionImprovements;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Models\ActionImprovement;
use App\Models\ActionImprovementEvidence;
use App\Models\ActionImprovementEvidenceKey;
use App\Models\Category;
use App\Models\Incident;
use App\Models\User;
use App\Services\ActionEvidenceStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Submit Proof (team-viewer role, owner 2026-11-20): restricted users upload
 * an encrypted proof file on actions of THEIR incidents (the ONE scope
 * decides) and optionally mark the action Done — which stamps completed_at
 * via the observer and stops reminders/escalation. Out-of-scope actions
 * abort 403 even when the action is called directly (visible() is UX only).
 */
class TeamViewerProofTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Incident $ownIncident;

    private Incident $foreignIncident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $team = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'IT Sec']);

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('team-viewer');
        $this->viewer->teams()->sync([$team->id]);

        $this->ownIncident = Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['IT Sec']]);
        $this->foreignIncident = Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['SRE']]);
    }

    private function ownAction(array $attrs = []): ActionImprovement
    {
        return ActionImprovement::factory()->create([
            'incident_id' => $this->ownIncident->id,
            'pic_email' => [$this->viewer->email],
            ...$attrs,
        ]);
    }

    public function test_proof_allowed_follows_the_one_scope(): void
    {
        $this->actingAs($this->viewer);

        $this->assertTrue(ActionImprovementFormSchema::proofAllowed($this->ownAction()));
        $this->assertFalse(ActionImprovementFormSchema::proofAllowed(
            ActionImprovement::factory()->create(['incident_id' => $this->foreignIncident->id])
        ), 'The grant is the incident scope, not PIC email coincidence');
    }

    /**
     * Owner 2026-11-20 approval flow: team-viewers are WORKERS — their
     * submission lands pending-approval and the action stays open so the
     * reminders keep chasing until an admin / incident PIC approves. Even a
     * forged mark_done=true (hidden Filament fields carry no data, but a
     * direct Livewire call could inject it) must not self-close: the
     * trusted-ness gate is server-side.
     */
    public function test_viewer_submission_awaits_approval_and_keeps_the_action_open(): void
    {
        Storage::fake('public');
        $action = $this->ownAction();

        Livewire::actingAs($this->viewer)
            ->test(ListActionImprovements::class)
            ->callTableAction('submit_proof', $action, data: [
                'file_path' => UploadedFile::fake()->createWithContent('proof.txt', 'done and verified'),
                'result_note' => 'Firewall rule deployed',
                'mark_done' => true, // FORGED — viewer is not a trusted approver
            ])
            ->assertHasNoTableActionErrors();

        $evidence = ActionImprovementEvidence::query()->where('action_improvement_id', $action->id)->firstOrFail();
        $this->assertSame('proof.txt', $evidence->original_filename);
        $this->assertStringStartsWith('action-evidence/', $evidence->file_path);
        $this->assertSame($this->viewer->id, $evidence->submitted_by, 'Worker submission carries the submitter');
        $this->assertNull($evidence->approved_at, 'Worker submission is pending until an Approve Proof');

        $ciphertext = Storage::disk('public')->get($evidence->file_path);
        $this->assertStringNotContainsString('done and verified', (string) $ciphertext, 'Proof must be encrypted at rest');

        $this->assertNotNull(ActionImprovementEvidenceKey::query()
            ->where('action_improvement_evidence_id', $evidence->id)->first());

        $this->assertNotNull(Audit::query()
            ->where('event', 'file_uploaded')
            ->where('auditable_type', Incident::class)
            ->where('auditable_id', $this->ownIncident->id)->first());

        $action->refresh();
        $this->assertSame('pending', $action->status, 'The forged mark_done must NOT close a worker submission');
        $this->assertSame('Firewall rule deployed', $action->result_note, 'The note still lands — the approver reads it');
        $this->assertNull($action->completed_at, 'Nothing completed — reminders keep chasing');
    }

    public function test_proof_without_mark_done_keeps_the_action_open(): void
    {
        Storage::fake('public');
        $action = $this->ownAction();

        Livewire::actingAs($this->viewer)
            ->test(ListActionImprovements::class)
            ->callTableAction('submit_proof', $action, data: [
                'file_path' => UploadedFile::fake()->createWithContent('photo.png', 'pixels'),
                'mark_done' => false,
            ])
            ->assertHasNoTableActionErrors();

        $action->refresh();
        $this->assertSame('pending', $action->status);
        $this->assertCount(1, $action->evidence()->get());
        $evidence = $action->evidence()->first();
        $this->assertSame($this->viewer->id, $evidence->submitted_by);
        $this->assertNull($evidence->approved_at);
        $this->assertTrue($evidence->isPendingApproval());
        $this->assertTrue($action->hasPendingProof());
    }

    public function test_out_of_scope_action_aborts_403_even_via_direct_call(): void
    {
        Storage::fake('public');
        $action = ActionImprovement::factory()->create(['incident_id' => $this->foreignIncident->id]);

        $this->actingAs($this->viewer);

        // visible() already hides the action (UX) — Livewire's testing layer
        // refuses to call it, so the server-side guard is called directly,
        // exactly as the action closure does.
        $this->expectException(HttpException::class);
        ActionImprovementFormSchema::guardSubmitProof($action);
    }

    public function test_in_scope_action_passes_the_guard(): void
    {
        $this->actingAs($this->viewer);

        ActionImprovementFormSchema::guardSubmitProof($this->ownAction());

        $this->assertTrue(true, 'Guard passed without aborting for an in-scope action');
    }

    public function test_viewer_cannot_send_reminders_but_managers_still_can(): void
    {
        $action = $this->ownAction();

        // send_reminder lives on the incident's Action Improvements relation
        // manager (the chase side), not the global resource page.
        Livewire::actingAs($this->viewer)
            ->test(ActionImprovementsRelationManager::class, [
                'ownerRecord' => $this->ownIncident,
                'pageClass' => \App\Filament\Resources\IncidentResource\Pages\ViewIncident::class,
            ])
            ->assertTableActionHidden('send_reminder', $action);

        $manager = User::factory()->create();
        $manager->assignRole('admin');

        Livewire::actingAs($manager)
            ->test(ActionImprovementsRelationManager::class, [
                'ownerRecord' => $this->ownIncident,
                'pageClass' => \App\Filament\Resources\IncidentResource\Pages\ViewIncident::class,
            ])
            ->assertTableActionVisible('send_reminder', $action);
    }

    public function test_evidence_store_rejects_missing_file(): void
    {
        $action = $this->ownAction();

        $this->expectException(\InvalidArgumentException::class);
        app(ActionEvidenceStore::class)->store($action, null, $this->viewer->id);
    }
}
