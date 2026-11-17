<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\InvestigationFormsRelationManager;
use App\Mail\InvestigationFormRequestMail;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The admin surface: per-incident, per-team requests, each recipient with
 * their OWN token link auto-emailed; resend rotates, revoke person vs whole
 * request, reissue re-opens, download buttons after submit.
 */
class InvestigationFormAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo(['view incidents', 'manage incidents']);

        return $user;
    }

    private function requestViaAction(Incident $incident, array $emails, string $team = 'Payments'): InvestigationFormRequest
    {
        Mail::fake();

        Livewire::actingAs($this->admin())
            ->test(InvestigationFormsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->callTableAction('request', data: [
                'team' => $team,
                'recipients' => $emails,
                'note' => 'please fill by Friday',
            ]);

        return InvestigationFormRequest::query()
            ->where('incident_id', $incident->id)
            ->where('team', $team)
            ->firstOrFail();
    }

    public function test_request_creates_one_row_and_one_own_link_mail_per_recipient(): void
    {
        $incident = Incident::factory()->create();
        $request = $this->requestViaAction($incident, ['a@x.com', 'b@x.com', 'c@x.com']);

        $this->assertSame(3, $request->recipients()->count());
        $this->assertCount(3, InvestigationFormRecipient::all());

        $hashes = $request->recipients()->pluck('token_hash')->all();
        $this->assertCount(3, array_unique($hashes), 'each recipient needs their own token');

        Mail::assertQueued(InvestigationFormRequestMail::class, 3);

        // Each mail carries its OWN link — the three links are distinct.
        $links = [];
        Mail::assertQueued(function (InvestigationFormRequestMail $mail) use (&$links): bool {
            preg_match('/investigation\/([a-f0-9]{64})/', (string) $mail->body, $m);
            $links[] = $m[1] ?? null;

            return true;
        });
        $this->assertCount(3, array_unique($links), 'links must be distinct per recipient');
        $this->assertNotContains(null, $links);
    }

    public function test_re_request_same_team_adds_only_new_emails(): void
    {
        $incident = Incident::factory()->create();
        $this->requestViaAction($incident, ['a@x.com', 'b@x.com']);
        $before = InvestigationFormRecipient::pluck('token_hash', 'email')->all();

        $request = $this->requestViaAction($incident, ['b@x.com', 'new@x.com']);

        $this->assertSame(3, $request->recipients()->count());
        $this->assertSame($before['b@x.com'], $request->recipients()->where('email', 'b@x.com')->first()->token_hash, 'existing recipient must not be rotated');
        $this->assertNotSame($before['a@x.com'], null);
    }

    public function test_resend_rotates_token_and_old_link_dies(): void
    {
        $incident = Incident::factory()->create();
        $request = $this->requestViaAction($incident, ['a@x.com']);
        $recipient = $request->recipients()->firstOrFail();
        $oldHash = $recipient->token_hash;

        Mail::fake();
        Livewire::actingAs($this->admin())
            ->test(InvestigationFormsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->callTableAction('resend', $recipient);

        $recipient->refresh();
        $this->assertNotSame($oldHash, $recipient->token_hash);
        $this->assertNotNull($recipient->emailed_at);
        Mail::assertQueued(InvestigationFormRequestMail::class, 1);
    }

    public function test_revoke_person_kills_only_that_link(): void
    {
        $incident = Incident::factory()->create();
        $request = $this->requestViaAction($incident, ['a@x.com', 'b@x.com']);
        $a = $request->recipients()->where('email', 'a@x.com')->firstOrFail();
        $b = $request->recipients()->where('email', 'b@x.com')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(InvestigationFormsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->callTableAction('revoke_person', $a);

        $this->assertTrue($a->fresh()->isRevoked());
        $this->assertFalse($a->fresh()->linkIsValid());
        $this->assertTrue($b->fresh()->linkIsValid());
        $this->assertSame(InvestigationFormRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_revoke_request_kills_all_links(): void
    {
        $incident = Incident::factory()->create();
        $request = $this->requestViaAction($incident, ['a@x.com', 'b@x.com']);

        Livewire::actingAs($this->admin())
            ->test(InvestigationFormsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->callTableAction('revoke_request', $request->recipients()->firstOrFail());

        $request->refresh();
        $this->assertSame(InvestigationFormRequest::STATUS_REVOKED, $request->status);
        foreach ($request->recipients as $recipient) {
            $this->assertFalse($recipient->linkIsValid());
        }
    }

    public function test_reissue_reopens_and_reemails_all(): void
    {
        $incident = Incident::factory()->create();
        $request = $this->requestViaAction($incident, ['a@x.com']);
        $recipient = $request->recipients()->firstOrFail();
        $request->update(['status' => InvestigationFormRequest::STATUS_REVOKED]);
        $recipient->update(['revoked_at' => now()]);
        $oldHash = $recipient->token_hash;

        Mail::fake();
        Livewire::actingAs($this->admin())
            ->test(InvestigationFormsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->callTableAction('reissue', $recipient);

        $request->refresh();
        $this->assertSame(InvestigationFormRequest::STATUS_PENDING, $request->status);
        $this->assertTrue($request->expires_at->isFuture());
        $this->assertNull($recipient->fresh()->revoked_at);
        $this->assertNotSame($oldHash, $recipient->fresh()->token_hash);
        Mail::assertQueued(InvestigationFormRequestMail::class, 1);
    }

    public function test_view_only_user_cannot_request(): void
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo(['view incidents']);
        $incident = Incident::factory()->create();
        Mail::fake();

        Livewire::actingAs($user)
            ->test(InvestigationFormsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->assertTableActionHidden('request');
    }

    public function test_relation_manager_scopes_to_own_incident_only(): void
    {
        $incidentA = Incident::factory()->create();
        $incidentB = Incident::factory()->create();
        $this->requestViaAction($incidentA, ['a@x.com'], 'TeamA');
        $this->requestViaAction($incidentB, ['b@x.com'], 'TeamB');

        $rowsA = InvestigationFormRecipient::query()
            ->whereHas('request', fn ($q) => $q->where('incident_id', $incidentA->id))
            ->count();

        $this->assertSame(1, $rowsA);
    }

    public function test_deleting_incident_cascades_requests_and_recipients(): void
    {
        $incident = Incident::factory()->create();
        $request = $this->requestViaAction($incident, ['a@x.com']);

        $incident->delete();

        $this->assertDatabaseMissing('investigation_form_requests', ['id' => $request->id]);
        $this->assertDatabaseCount('investigation_form_recipients', 0);
    }

    public function test_submitted_request_download_actions_point_at_incident_documents(): void
    {
        $incident = Incident::factory()->create();
        $request = $this->requestViaAction($incident, ['a@x.com']);
        $docx = InvestigationDocument::factory()->for($incident)->create();
        $pdf = InvestigationDocument::factory()->for($incident)->create();
        $request->update([
            'status' => InvestigationFormRequest::STATUS_SUBMITTED,
            'docx_document_id' => $docx->id,
            'pdf_document_id' => $pdf->id,
        ]);

        $this->assertSame($incident->id, $request->docxDocument->incident_id);
        $this->assertSame($incident->id, $request->pdfDocument->incident_id);
        $this->assertSame(2, InvestigationDocument::query()->count());
    }
}
