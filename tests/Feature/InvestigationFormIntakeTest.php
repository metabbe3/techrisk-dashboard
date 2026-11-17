<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ConvertDocumentToMarkdown;
use App\Livewire\InvestigationFormIntake;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use App\Services\InvestigationForm\InvestigationFormSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The no-login intake page: token guard (hash-only, generic invalid page),
 * the shared team draft, the AI steps (faked at the Go brain HTTP edge),
 * submit guards and the successful submit (DOCX + PDF on the incident).
 */
class InvestigationFormIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.go_agent.token', 'secret');
        config()->set('ai.go_agent.base_url', 'http://brain.test');
        Storage::fake('public');
        Queue::fake();
    }

    private function makeLink(?InvestigationFormRequest $request = null, string $team = 'Payments'): array
    {
        $incident = Incident::factory()->create();
        $request ??= InvestigationFormRequest::create([
            'incident_id' => $incident->id,
            'team' => $team,
            'status' => InvestigationFormRequest::STATUS_PENDING,
            'expires_at' => now()->addDays(14),
            'created_by' => User::factory()->create()->id,
        ]);
        $plain = InvestigationFormRecipient::newToken();
        $recipient = $request->recipients()->create([
            'email' => 'a@x.com',
            'token_hash' => InvestigationFormRecipient::hashToken($plain),
            'emailed_at' => now(),
        ]);

        return [$request, $recipient, $plain];
    }

    private function fillRequired($component): void
    {
        foreach (InvestigationFormSchema::flatFields() as $key => $label) {
            [$section, $field] = explode('.', $key, 2);
            $component->set("answers.{$section}.{$field}", 'value for '.$key);
        }

        $component->set('chronology', [['time' => '2026.11.01 10:00', 'event' => 'Alert fired']]);
    }

    public function test_invalid_expired_revoked_and_submitted_tokens_all_render_the_same_page(): void
    {
        $incident = Incident::factory()->create();

        $make = function (string $status, ?\DateTimeInterface $expires, $revoked) use ($incident): string {
            $request = InvestigationFormRequest::create([
                'incident_id' => $incident->id,
                'team' => 'T'.$status.uniqid(),
                'status' => $status,
                'expires_at' => $expires,
                'created_by' => User::factory()->create()->id,
            ]);
            $plain = InvestigationFormRecipient::newToken();
            $request->recipients()->create([
                'email' => 'x@x.com',
                'token_hash' => InvestigationFormRecipient::hashToken($plain),
                'emailed_at' => now(),
                'revoked_at' => $revoked,
            ]);

            return $plain;
        };

        $expired = $make(InvestigationFormRequest::STATUS_PENDING, now()->subDay(), null);
        $revoked = $make(InvestigationFormRequest::STATUS_PENDING, now()->addDays(14), now());
        $submitted = $make(InvestigationFormRequest::STATUS_SUBMITTED, now()->addDays(14), null);

        foreach (['totally-bad-token', $expired, $revoked, $submitted] as $token) {
            Livewire::test(InvestigationFormIntake::class, ['token' => $token])
                ->assertSee('This link is not valid');
        }
    }

    public function test_concurrent_edit_warns_when_a_teammate_saved_first(): void
    {
        [$request, , $plainA] = $this->makeLink();
        $plainB = InvestigationFormRecipient::newToken();
        $request->recipients()->create([
            'email' => 'b@x.com',
            'token_hash' => InvestigationFormRecipient::hashToken($plainB),
            'emailed_at' => now(),
        ]);

        // A saves once so the draft has a timestamp.
        Livewire::test(InvestigationFormIntake::class, ['token' => $plainA])
            ->set('answers.header.incident_name', 'first save')
            ->call('saveDraft');

        // B loads the draft (loadedDraftAt = T1), then A saves again (T2).
        $b = Livewire::test(InvestigationFormIntake::class, ['token' => $plainB])
            ->assertSet('answers.header.incident_name', 'first save');

        Livewire::test(InvestigationFormIntake::class, ['token' => $plainA])
            ->set('answers.header.incident_name', 'second save')
            ->call('saveDraft');

        // B's stale save must be refused, not silently overwrite A.
        $b->set('answers.header.incident_name', 'B stale edit')
            ->call('saveDraft')
            ->assertHasErrors(['draft']);

        $request->refresh();
        $this->assertSame('second save', $request->answers['header']['incident_name']);
    }

    public function test_plain_token_is_never_stored(): void
    {
        [, , $plain] = $this->makeLink();

        $this->assertDatabaseMissing('investigation_form_recipients', ['token_hash' => $plain]);
        $this->assertSame(64, strlen(InvestigationFormRecipient::hashToken($plain)));
    }

    public function test_shared_draft_a_saves_b_loads_b_submits_then_a_is_dead(): void
    {
        [$request, $recipientA, $plainA] = $this->makeLink();
        $plainB = InvestigationFormRecipient::newToken();
        $recipientB = $request->recipients()->create([
            'email' => 'b@x.com',
            'token_hash' => InvestigationFormRecipient::hashToken($plainB),
            'emailed_at' => now(),
        ]);

        Livewire::test(InvestigationFormIntake::class, ['token' => $plainA])
            ->set('answers.header.incident_name', 'The outage')
            ->call('saveDraft');

        Livewire::test(InvestigationFormIntake::class, ['token' => $plainB])
            ->assertSet('answers.header.incident_name', 'The outage');

        // B completes and submits.
        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plainB]);
        $this->fillRequired($component);
        $component->set('consentAccepted', true)
            ->set('consentEmail', 'b@x.com')
            ->call('submit')
            ->assertHasNoErrors();

        $request->refresh();
        $this->assertSame(InvestigationFormRequest::STATUS_SUBMITTED, $request->status);

        // A's link is dead — every action re-checks.
        Livewire::test(InvestigationFormIntake::class, ['token' => $plainA])
            ->assertSee('This link is not valid');
    }

    public function test_ai_extraction_prefills_fields_and_missing_questions(): void
    {
        [, , $plain] = $this->makeLink();

        $output = json_encode([
            'header' => ['incident_name' => 'DB timeout', 'unknown_key' => 'drop me'],
            'summary' => ['description' => 'API timeouts at 10:00'],
            'chronology' => [['time' => '10:00', 'event' => 'Alert fired']],
            'missing' => [
                ['field' => 'summary.business_impact', 'question' => 'What was the impact?'],
                ['field' => 'totally.unknown', 'question' => 'should be dropped'],
            ],
        ]);

        Http::fake([
            'brain.test/internal/run' => Http::response(['ok' => true, 'output' => $output, 'model' => 'test-model']),
        ]);

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('name', 'Alice')
            ->set('pasteText', 'timeline: alert at 10:00')
            ->call('extractFromSource')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $component->assertSet('answers.header.incident_name', 'DB timeout');
        $component->assertSet('answers.summary.description', 'API timeouts at 10:00');
        $component->assertSet('chronology.0.event', 'Alert fired');

        // missing[] carries only schema-known fields.
        $missing = $component->get('missing');
        $this->assertCount(1, $missing);
        $this->assertSame('summary.business_impact', $missing[0]['field']);

        // Unknown keys from the AI never land.
        $component->assertSet('answers.unknown_key', null);
    }

    public function test_ai_call_cap_is_enforced(): void
    {
        [, , $plain] = $this->makeLink();

        Http::fake(['brain.test/internal/run' => Http::response(['ok' => true, 'output' => '{}'])]);

        Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('aiCalls', 10)
            ->set('pasteText', 'x')
            ->call('extractFromSource')
            ->assertHasErrors(['notionUrl']);
    }

    public function test_submit_is_blocked_without_consent_or_mismatched_email(): void
    {
        [, , $plain] = $this->makeLink();

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain]);
        $this->fillRequired($component);

        $component->set('consentAccepted', false)
            ->set('consentEmail', 'a@x.com')
            ->call('submit')
            ->assertHasErrors(['consent']);

        $component->set('consentAccepted', true)
            ->set('consentEmail', 'wrong@x.com')
            ->call('submit')
            ->assertHasErrors(['consent']);

        $this->assertDatabaseHas('investigation_form_requests', ['id' => $this->pendingId($component), 'status' => InvestigationFormRequest::STATUS_PENDING]);
    }

    public function test_submit_blocked_while_required_fields_empty(): void
    {
        [, , $plain] = $this->makeLink();

        Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('consentAccepted', true)
            ->set('consentEmail', 'a@x.com')
            ->call('submit')
            ->assertHasErrors(['consent']);

        $this->assertSame(0, InvestigationDocument::count(), 'a blocked submit must not create documents');
    }

    public function test_successful_submit_creates_two_encrypted_documents_and_consumes_the_token(): void
    {
        [$request, $recipient, $plain] = $this->makeLink();

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain]);
        $this->fillRequired($component);
        $component->set('name', 'Alice Submitter')
            ->set('consentAccepted', true)
            ->set('consentEmail', 'a@x.com')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('submitted');

        $request->refresh();
        $this->assertSame(InvestigationFormRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame('a@x.com', $request->submitter_email);
        $this->assertSame('Alice Submitter', $request->submitter_name);
        $this->assertNotNull($request->consented_at);
        $this->assertNotNull($request->submitted_at);
        $this->assertNotNull($request->docx_document_id);
        $this->assertNotNull($request->pdf_document_id);
        $this->assertSame($request->incident_id, $request->docxDocument->incident_id);
        $this->assertSame($request->incident_id, $request->pdfDocument->incident_id);
        $this->assertNotNull($request->docxDocument->encryptionKey, 'generated docx must be encrypted at rest');

        // A follow-up submit through the consumed token renders the invalid page.
        Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->assertSee('This link is not valid');

        Queue::assertPushed(ConvertDocumentToMarkdown::class, 2);
    }

    private function pendingId($component): int
    {
        /** @var InvestigationFormRequest $request */
        $request = $component->get('formRequest');

        return $request->id;
    }
}
