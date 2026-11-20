<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Mail\GroupNotificationMail;
use App\Models\ActionImprovement;
use App\Models\ActionImprovementEvidence;
use App\Models\ActionImprovementEvidenceKey;
use App\Models\Category;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\ActionImprovementProofApproved;
use App\Notifications\ActionImprovementProofSubmitted;
use App\Notifications\ChannelFilteredNotification;
use App\Services\ActionEvidenceStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Proof approval flow (owner 2026-11-20 two-PIC model): admins + incident
 * PICs are TRUSTED — their Submit Proof may self-close via mark_done. The
 * action's pic_email assignees (team-viewer role after SSO) are WORKERS —
 * every submission lands pending-approval, the action stays open so the
 * reminders keep chasing, and Approve Proof (trusted only) stamps ALL
 * pending evidence, closes the action and notifies each submitter. The
 * approver email is ONE combined message (multi-TO, owner 2026-10-09).
 */
class ActionProofApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pic;

    private User $pic2;

    private User $worker;

    /** A team-viewer inside the incident's team but NOT an approver. */
    private User $otherViewer;

    private Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        // pic/pic2 are role-less users — with app.debug on, Livewire's
        // dev-only multi-root check 500s any Filament page they render
        // (documented trap; the same guard the sidebar baseline notes).
        config(['app.debug' => false]);

        $team = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'IT Sec']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        // Incident PICs are portal users ('user' role) — identity, not the
        // admin role, is what makes them trusted approvers.
        $this->pic = User::factory()->create();
        $this->pic->assignRole('user');
        $this->pic2 = User::factory()->create();
        $this->pic2->assignRole('user');

        $this->worker = User::factory()->create();
        $this->worker->assignRole('team-viewer');
        $this->worker->teams()->sync([$team->id]);

        $this->otherViewer = User::factory()->create();
        $this->otherViewer->assignRole('team-viewer');
        $this->otherViewer->teams()->sync([$team->id]);

        $this->incident = Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['IT Sec']]);
        $this->incident->pics()->attach([$this->pic->id, $this->pic2->id]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function action(array $attrs = []): ActionImprovement
    {
        return ActionImprovement::factory()->create([
            'incident_id' => $this->incident->id,
            'pic_email' => [$this->worker->email],
            ...$attrs,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submitGlobal(User $user, ActionImprovement $action, array $data)
    {
        return Livewire::actingAs($user)
            ->test(\App\Filament\Resources\ActionImprovementResource\Pages\ListActionImprovements::class)
            ->callTableAction('submit_proof', $action, data: $data);
    }

    private function assertPendingProof(ActionImprovement $action, User $submitter): ActionImprovementEvidence
    {
        $evidence = ActionImprovementEvidence::query()
            ->where('action_improvement_id', $action->id)
            ->firstOrFail();

        $this->assertSame($submitter->id, $evidence->submitted_by);
        $this->assertNull($evidence->approved_at);
        $this->assertTrue($evidence->isPendingApproval());
        $this->assertTrue($action->fresh()->hasPendingProof());

        return $evidence;
    }

    private function relationManager(User $user)
    {
        return Livewire::actingAs($user)
            ->test(ActionImprovementsRelationManager::class, [
                'ownerRecord' => $this->incident,
                'pageClass' => ViewIncident::class,
            ]);
    }

    public function test_incident_pic_submission_with_mark_done_auto_closes(): void
    {
        Storage::fake('public');
        Mail::fake();
        $action = $this->action();

        $this->submitGlobal($this->pic, $action, [
            'file_path' => UploadedFile::fake()->createWithContent('proof.txt', 'closed personally'),
            'result_note' => 'Verified on the spot',
            'mark_done' => true,
        ])->assertHasNoTableActionErrors();

        $action->refresh();
        $this->assertSame('done', $action->status, 'Trusted submission self-closes');
        $this->assertSame('Verified on the spot', $action->result_note);
        $this->assertNotNull($action->completed_at);
        $this->assertSame($this->pic->id, $action->completed_by);

        $evidence = $action->evidence()->firstOrFail();
        $this->assertNull($evidence->submitted_by, 'A self-closed row never counts as pending');
        $this->assertNotNull($evidence->approved_at, 'Stamped approved at creation — a done action carries no awaiting-approval evidence');
        $this->assertSame($this->pic->id, $evidence->approved_by);
        $this->assertFalse($action->hasPendingProof());

        Mail::assertNothingSent('Self-close needs no approver email');
    }

    public function test_admin_submission_self_closes_the_same_way(): void
    {
        Storage::fake('public');
        Mail::fake();
        $action = $this->action();

        $this->submitGlobal($this->admin, $action, [
            'file_path' => UploadedFile::fake()->createWithContent('shot.png', 'pixels'),
            'mark_done' => true,
        ])->assertHasNoTableActionErrors();

        $action->refresh();
        $this->assertSame('done', $action->status);
        $this->assertSame($this->admin->id, $action->completed_by);
        $this->assertFalse($action->hasPendingProof());
        Mail::assertNothingSent();
    }

    public function test_trusted_submission_without_mark_done_awaits_approval_and_emails_approvers_once(): void
    {
        Storage::fake('public');
        Mail::fake();
        $action = $this->action();

        // pic2 submits but does NOT self-close — approvers = pics + admins
        // minus the submitter = pic + admin → still ONE combined email.
        $this->submitGlobal($this->pic2, $action, [
            'file_path' => UploadedFile::fake()->createWithContent('proof.txt', 'awaiting review'),
            'mark_done' => false,
        ])->assertHasNoTableActionErrors();

        $this->assertPendingProof($action, $this->pic2);
        $this->assertSame('pending', $action->fresh()->status, 'The action stays open — reminders keep chasing');

        Mail::assertSent(GroupNotificationMail::class, 1);

        Notification::assertSentTo($this->pic, ChannelFilteredNotification::class,
            fn (ChannelFilteredNotification $n) => $n->databaseType() === ActionImprovementProofSubmitted::class);
        Notification::assertSentTo($this->admin, ChannelFilteredNotification::class,
            fn (ChannelFilteredNotification $n) => $n->databaseType() === ActionImprovementProofSubmitted::class);
        Notification::assertNotSentTo($this->pic2, ChannelFilteredNotification::class,
            fn (ChannelFilteredNotification $n) => $n->databaseType() === ActionImprovementProofSubmitted::class,
            'The submitter is never emailed about their own submission');
    }

    public function test_worker_submission_is_pending_even_with_a_forged_mark_done(): void
    {
        Storage::fake('public');
        Mail::fake();
        $action = $this->action();

        $this->submitGlobal($this->worker, $action, [
            'file_path' => UploadedFile::fake()->createWithContent('proof.txt', 'x'),
            'mark_done' => true, // FORGED — worker is not trusted
        ])->assertHasNoTableActionErrors();

        $this->assertPendingProof($action, $this->worker);
        $this->assertSame('pending', $action->fresh()->status);
        $this->assertNull($action->fresh()->completed_at);
        Mail::assertSent(GroupNotificationMail::class, 1);
    }

    public function test_approve_proof_visibility_follows_trusted_approvers(): void
    {
        Storage::fake('public');
        $action = $this->action();

        $this->submitGlobal($this->worker, $action, [
            'file_path' => UploadedFile::fake()->createWithContent('proof.txt', 'x'),
        ])->assertHasNoTableActionErrors();

        $this->relationManager($this->admin)->assertTableActionVisible('approve_proof', $action);
        $this->relationManager($this->pic)->assertTableActionVisible('approve_proof', $action);
        $this->relationManager($this->worker)->assertTableActionHidden('approve_proof', $action);
        $this->relationManager($this->otherViewer)->assertTableActionHidden('approve_proof', $action);
    }

    public function test_approve_stamps_all_pending_rows_closes_the_action_and_notifies_submitters(): void
    {
        Storage::fake('public');
        $action = $this->action();

        // File + link in one submission = TWO pending evidence rows.
        $this->submitGlobal($this->worker, $action, [
            'file_path' => UploadedFile::fake()->createWithContent('proof.txt', 'x'),
            'link_url' => 'https://chat.example.com/msg/42',
        ])->assertHasNoTableActionErrors();

        $this->assertSame(2, $action->evidence()->whereNotNull('submitted_by')->whereNull('approved_at')->count());

        $this->relationManager($this->admin)
            ->callTableAction('approve_proof', $action)
            ->assertHasNoTableActionErrors();

        $action->refresh();
        $this->assertSame('done', $action->status, 'Approval auto-closes the action');
        $this->assertNotNull($action->completed_at);
        $this->assertSame($this->admin->id, $action->completed_by, 'The approver is recorded as who completed it');
        $this->assertFalse($action->hasPendingProof());

        $this->assertSame(2, $action->evidence()->whereNotNull('approved_at')->where('approved_by', $this->admin->id)->count(),
            'ALL pending rows stamped — no awaiting-approval evidence survives on a done action');

        Notification::assertSentTo($this->worker, ChannelFilteredNotification::class,
            fn (ChannelFilteredNotification $n) => $n->databaseType() === ActionImprovementProofApproved::class);
    }

    public function test_worker_cannot_approve_even_with_pending_proof(): void
    {
        $action = $this->action();
        app(ActionEvidenceStore::class)->store($action, UploadedFile::fake()->create('p.txt'), $this->worker->id, pendingApproval: true);

        $this->actingAs($this->worker);

        $this->expectException(HttpException::class);
        ActionImprovementFormSchema::guardApproveProof($action);
    }

    public function test_non_pic_viewer_cannot_approve(): void
    {
        $action = $this->action();
        app(ActionEvidenceStore::class)->store($action, UploadedFile::fake()->create('p.txt'), $this->worker->id, pendingApproval: true);

        $this->actingAs($this->otherViewer);

        $this->expectException(HttpException::class);
        ActionImprovementFormSchema::guardApproveProof($action);
    }

    public function test_cannot_approve_a_done_action(): void
    {
        $action = $this->action(['status' => ActionImprovement::STATUS_DONE, 'completed_at' => now(), 'completed_by' => $this->admin->id]);
        app(ActionEvidenceStore::class)->store($action, UploadedFile::fake()->create('p.txt'), $this->worker->id, pendingApproval: true);

        $this->actingAs($this->admin);

        $this->expectException(HttpException::class);
        ActionImprovementFormSchema::guardApproveProof($action);
    }

    public function test_admin_tab_evidence_uploads_are_never_pending(): void
    {
        Storage::fake('public');
        $action = $this->action();

        app(ActionEvidenceStore::class)->store($action, UploadedFile::fake()->create('a.txt'), $this->admin->id);

        $evidence = $action->evidence()->firstOrFail();
        $this->assertNull($evidence->submitted_by);
        $this->assertFalse($action->hasPendingProof(), 'Plain admin uploads are attachments, not approval requests');

        $this->actingAs($this->admin);

        $this->expectException(HttpException::class);
        ActionImprovementFormSchema::guardApproveProof($action);
    }

    public function test_reminders_keep_chasing_until_approval_then_stop(): void
    {
        $action = $this->action([
            'reminder' => true,
            'reminder_frequency' => 'weekly',
            'status' => ActionImprovement::STATUS_PENDING,
            'due_date' => now()->subDay()->startOfDay(),
        ]);

        $this->submitGlobal($this->worker, $action, [
            'file_path' => UploadedFile::fake()->createWithContent('proof.txt', 'x'),
            'mark_done' => false,
        ])->assertHasNoTableActionErrors();

        Mail::fake();
        Artisan::call('reminders:send-action-improvements');
        Mail::assertSent(GroupNotificationMail::class, 1, 'A pending-approval action is still open work — the chase continues');

        $this->relationManager($this->admin)
            ->callTableAction('approve_proof', $action)
            ->assertHasNoTableActionErrors();

        $this->assertSame('done', $action->fresh()->status);

        Artisan::call('reminders:send-action-improvements');
        Mail::assertSent(GroupNotificationMail::class, 1, 'Done actions fall out of openStatuses — no new reminder');
    }

    public function test_link_only_submission_creates_link_evidence_and_download_redirects(): void
    {
        Storage::fake('public');
        $action = $this->action();

        $this->submitGlobal($this->worker, $action, [
            'link_url' => 'https://notion.so/rca-abc123',
            'result_note' => 'RCA written in Notion',
        ])->assertHasNoTableActionErrors();

        $evidence = $this->assertPendingProof($action, $this->worker);
        $this->assertNull($evidence->file_path);
        $this->assertNull($evidence->original_filename);
        $this->assertSame('https://notion.so/rca-abc123', $evidence->link_url);
        $this->assertTrue($evidence->isLink());

        $this->assertTrue(ActionImprovementEvidenceKey::query()
            ->where('action_improvement_evidence_id', $evidence->id)->doesntExist(),
            'Links are not encrypted — no key row');

        $this->assertNotNull(Audit::query()
            ->where('event', 'link_submitted')
            ->where('auditable_type', Incident::class)
            ->where('auditable_id', $this->incident->id)->first());

        // The gate runs BEFORE the redirect — an unauthorized user gets 403,
        // an authorized one rides through to the URL.
        $this->actingAs($this->worker)
            ->get(route('action-evidence.download', $evidence))
            ->assertRedirect('https://notion.so/rca-abc123');

        $this->actingAs($this->admin)
            ->get(route('action-evidence.download', $evidence))
            ->assertRedirect('https://notion.so/rca-abc123');
    }

    public function test_submission_requires_a_file_or_a_link(): void
    {
        $action = $this->action();

        $this->submitGlobal($this->worker, $action, ['result_note' => 'words only'])
            ->assertHasTableActionErrors();

        $this->submitGlobal($this->worker, $action, ['link_url' => 'not a url'])
            ->assertHasTableActionErrors();

        $this->assertSame(0, $action->evidence()->count());
    }

    public function test_kill_switch_suppresses_the_email_but_not_the_submission(): void
    {
        Storage::fake('public');
        Mail::fake();
        \App\Models\Setting::set('netcore_enabled', false);
        $action = $this->action();

        $this->submitGlobal($this->worker, $action, [
            'file_path' => UploadedFile::fake()->createWithContent('proof.txt', 'x'),
        ])->assertHasNoTableActionErrors();

        $this->assertPendingProof($action, $this->worker);

        Mail::assertNothingSent('The global kill-switch suppresses the approver email');

        Notification::assertSentTo($this->pic, ChannelFilteredNotification::class,
            fn (ChannelFilteredNotification $n) => $n->databaseType() === ActionImprovementProofSubmitted::class,
            'In-app notifications are unaffected by the email kill-switch');
    }
}
