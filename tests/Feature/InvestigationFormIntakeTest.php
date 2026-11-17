<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ConvertDocumentToMarkdown;
use App\Jobs\ExtractInvestigationFormSource;
use App\Livewire\InvestigationFormIntake;
use App\Mail\InvestigationFormRequestMail;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use App\Services\InvestigationForm\InvestigationFormSchema;
use App\Services\InvestigationForm\InvestigationFormSettings;
use App\Services\Notion\NotionReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
            $value = in_array($key, InvestigationFormSchema::DATETIME_FIELDS, true)
                ? '2026-11-01T10:00'
                : 'value for '.$key;
            $component->set("answers.{$section}.{$field}", $value);
        }

        $component->set('chronology', [['time' => '2026-11-01T10:00', 'event' => 'Alert fired']]);
    }

    /**
     * The extraction is queued (the Dana edge proxy 504s long Livewire
     * requests): run the last dispatched job inline, then poll for the result.
     */
    private function runExtraction($component): void
    {
        $jobs = Queue::pushed(ExtractInvestigationFormSource::class);
        $this->assertNotEmpty($jobs, 'extraction job was not dispatched');
        $jobs->last()->handle();
        $component->call('pollExtraction');
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
            ->assertHasNoErrors();

        $this->runExtraction($component);

        $component->assertSet('step', 2);

        $component->assertSet('answers.header.incident_name', 'DB timeout');
        $component->assertSet('answers.summary.description', 'API timeouts at 10:00');
        // A bare time can't fill a datetime picker — it rides the event text.
        $component->assertSet('chronology.0.time', '');
        $component->assertSet('chronology.0.event', 'Alert fired [source time: 10:00]');

        // missing[] carries only schema-known fields.
        $missing = $component->get('missing');
        $this->assertCount(1, $missing);
        $this->assertSame('summary.business_impact', $missing[0]['field']);

        // Unknown keys from the AI never land.
        $component->assertSet('answers.unknown_key', null);
    }

    public function test_notion_link_sends_the_real_page_id_and_surfaces_notions_refusal(): void
    {
        [, , $plain] = $this->makeLink();

        // Real links from the field: slug hex letters ("RootCaus-e-", "v1-0-")
        // must never glue onto the id.
        $cases = [
            'https://www.notion.so/dana-id/MOTO-3DS-VM-Flow-RootCause-1022556e44dd824cb10e0174d0ed17e8?source=copy_link' => '1022556e44dd824cb10e0174d0ed17e8',
            'https://www.notion.so/dana-id/ID-TRI-PRO-01-System-and-Infrastructure-Drilling-Procedure-v1-0-d352556e44dd82739f5f813575cb86ed?source=copy_link' => 'd352556e44dd82739f5f813575cb86ed',
            'd352556e-44dd-8273-9f5f-813575cb86ed' => 'd352556e-44dd-8273-9f5f-813575cb86ed',
            'd352556e44dd82739f5f813575cb86ed' => 'd352556e44dd82739f5f813575cb86ed',
        ];

        $notion = $this->mock(NotionReadService::class);
        foreach ($cases as $id) {
            $notion->shouldReceive('readFullPage')->once()->with($id)
                ->andReturn(['body' => null, 'images' => 0, 'truncated' => false, 'error' => 'object_not_found']);
        }

        foreach (array_keys($cases) as $url) {
            $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
                ->set('notionUrl', $url)
                ->call('extractFromSource')
                ->assertHasNoErrors(); // the dispatch succeeds — the refusal comes back through the poll

            $this->runExtraction($component);

            $component->assertSet('step', 1);
            $this->assertStringContainsString('Notion said: object_not_found', $component->get('extractError'));
        }
    }

    public function test_ai_datetime_output_is_normalized_and_incomplete_dates_are_asked_not_guessed(): void
    {
        // incident_date always prefills (non-nullable on the incident) — the
        // AI test fields are ones the incident record doesn't cover.
        [, , $plain] = $this->makeLink();

        // One fake, two responses in order — a second Http::fake() on the
        // same URL MERGES with the first stub, so the first would keep winning.
        $outputs = [
            json_encode([
                'header' => ['statement_date' => '2026.11.01 10:00'], // separators + seconds → normalized
                'action_items' => [
                    ['item' => 'Add alert', 'due_date' => '2026/12/01 09:30', 'verification' => '', 'owner' => 'A'],
                    ['item' => 'Write RCA', 'due_date' => 'TBD', 'verification' => '', 'owner' => 'B'],
                    ['item' => 'Patch probe', 'due_date' => 'end of month', 'verification' => '', 'owner' => 'C'],
                ],
                'missing' => [],
            ]),
            json_encode(['header' => ['statement_date' => '2026-11-01'], 'missing' => []]), // bare date → never guessed
        ];
        $call = 0;
        Http::fake([
            'brain.test/internal/run' => function () use (&$call, $outputs) {
                return Http::response(['ok' => true, 'output' => $outputs[$call++], 'model' => 'test-model']);
            },
        ]);

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('pasteText', 'x')
            ->call('extractFromSource')
            ->assertHasNoErrors();

        $this->runExtraction($component);

        $component->assertSet('answers.header.statement_date', '2026-11-01T10:00');

        $component->assertSet('actionItems.0.due_date', '2026-12-01T09:30');
        $component->assertSet('actionItems.1.due_date', ''); // TBD stays empty, no note
        $component->assertSet('actionItems.1.item', 'Write RCA');
        $component->assertSet('actionItems.2.due_date', '');
        $component->assertSet('actionItems.2.item', 'Patch probe [source due date: end of month]');

        // Second extract on a FRESH link: a bare date is never guessed into
        // the picker — it comes back as a question quoting the source value.
        // (On the same link the draft persisted by the first extract would
        // legitimately win over the AI's bare date — that's draft > AI.)
        [, , $plain2] = $this->makeLink();
        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain2])
            ->set('pasteText', 'x')
            ->call('extractFromSource')
            ->assertHasNoErrors();

        $this->runExtraction($component);

        $component->assertSet('answers.header.statement_date', '');

        $missing = collect($component->get('missing'))->pluck('question')->implode('|');
        $this->assertStringContainsString('2026-11-01', $missing, 'the unparseable source value is quoted back');
        $this->assertStringContainsString('pick the exact date and time', $missing);
    }

    public function test_incident_times_prefill_blank_datetime_fields_and_draft_values_win(): void
    {
        $incident = Incident::factory()->create([
            'incident_date' => '2026-11-01 08:30:00',
            'discovered_at' => '2026-11-01 08:45:00',
            'stop_bleeding_at' => '2026-11-01 09:10:00',
        ]);
        $request = InvestigationFormRequest::create([
            'incident_id' => $incident->id,
            'team' => 'Payments',
            'status' => InvestigationFormRequest::STATUS_PENDING,
            'expires_at' => now()->addDays(14),
            'created_by' => User::factory()->create()->id,
            'answers' => ['header' => ['incident_date' => '2026-11-01T08:31']], // teammate already picked
        ]);
        $plain = InvestigationFormRecipient::newToken();
        $request->recipients()->create([
            'email' => 'a@x.com',
            'token_hash' => InvestigationFormRecipient::hashToken($plain),
            'emailed_at' => now(),
        ]);

        Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->assertSet('answers.header.incident_date', '2026-11-01T08:31') // a saved draft value is never overwritten
            ->assertSet('answers.summary.occurred_time', '2026-11-01T08:30')
            ->assertSet('answers.summary.discovery_time', '2026-11-01T08:45')
            ->assertSet('answers.summary.stop_bleeding_time', '2026-11-01T09:10');
    }

    public function test_submit_rejects_non_canonical_datetime_values(): void
    {
        [, , $plain] = $this->makeLink();

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain]);
        $this->fillRequired($component);

        // An old free-text draft (pre-picker) can't be printed or compared.
        $component->set('answers.header.incident_date', '2026.11.01 10:00')
            ->set('consentAccepted', true)
            ->set('consentEmail', 'a@x.com')
            ->call('submit')
            ->assertHasErrors(['consent']);

        $this->assertSame(0, InvestigationDocument::count(), 'a rejected submit must not create documents');

        $component->set('chronology', [['time' => '2026.11.01 10:00', 'event' => 'Alert fired']])
            ->set('answers.header.incident_date', '2026-11-01T10:00')
            ->call('submit')
            ->assertHasErrors(['consent']); // a non-canonical chronology time is rejected too

        $this->assertSame(0, InvestigationDocument::count());
    }

    public function test_ai_call_cap_is_enforced(): void
    {
        [, , $plain] = $this->makeLink();

        Http::fake(['brain.test/internal/run' => Http::response(['ok' => true, 'output' => '{}'])]);

        // aiCalls is #[Locked]: a client-side $wire.set('aiCalls', 0) once
        // bypassed the cap on this no-login page. Setting it must now throw.
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('aiCalls', 10);
    }

    public function test_ai_calls_increment_server_side_and_block_at_the_cap(): void
    {
        [$request, , $plain] = $this->makeLink();

        Http::fake(['brain.test/internal/run' => Http::response(['ok' => true, 'output' => '{}'])]);

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('pasteText', 'x');

        for ($i = 0; $i < 10; $i++) {
            $component->call('extractFromSource');
            // Only one extraction may run at a time — finish each dispatch
            // (without polling) so the next loop call is allowed.
            $request->refresh()->update(['extraction_status' => 'done']);
        }

        $this->assertSame(10, $component->instance()->aiCalls);

        // The 11th is the cap. The 10 loop calls already spent the limiter,
        // so clear it and go straight at the cap check.
        Cache::flush();

        $instance = $component->instance();
        try {
            $instance->extractFromSource();
            $this->fail('cap not enforced');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('AI call limit', $e->getMessage().implode(' ', array_column($e->errors(), '0')));
        }
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

    public function test_notion_notes_flag_unreadable_images_and_a_truncated_page(): void
    {
        [, , $plain] = $this->makeLink();

        $this->mock(NotionReadService::class)
            ->shouldReceive('readFullPage')->twice()
            ->andReturn(
                ['body' => 'Root cause: DB died.', 'images' => 2, 'truncated' => true, 'error' => ''],
                ['body' => 'All text.', 'images' => 0, 'truncated' => false, 'error' => ''],
            );

        Http::fake(['brain.test/internal/run' => Http::response(['ok' => true, 'output' => '{}', 'model' => 'm'])]);

        $first = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('notionUrl', 'https://www.notion.so/1022556e44dd824cb10e0174d0ed17e8')
            ->call('extractFromSource');

        $this->runExtraction($first);

        $first->assertSet('step', 2);

        $notes = $first->get('sourceNotes');
        $this->assertCount(2, $notes);
        $this->assertStringContainsString('2 image(s) on the Notion page could not be read', $notes[0]);
        $this->assertStringContainsString('96,000 characters', $notes[1]);

        // A clean page raises no notes.
        $second = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('notionUrl', 'https://www.notion.so/1022556e44dd824cb10e0174d0ed17e8')
            ->call('extractFromSource');

        $this->runExtraction($second);

        $this->assertSame([], $second->get('sourceNotes'));
    }

    public function test_extraction_appends_unseen_rows_and_keeps_draft_rows(): void
    {
        [$request, , $plain] = $this->makeLink();
        $request->update(['answers' => [
            'chronology' => [
                ['time' => '2026-11-01T10:00', 'event' => 'Alert fired'],
                ['time' => '', 'event' => 'Investigator called'],
            ],
            'action_items' => [['item' => 'Add regression case', 'due_date' => '2026-11-20T09:00', 'verification' => '', 'owner' => 'Budi']],
        ]]);

        Http::fake(['brain.test/internal/run' => Http::response([
            'ok' => true,
            'output' => json_encode([
                'chronology' => [
                    ['time' => '2026-11-01T09:55', 'event' => 'alert FIRED '], // same text → the draft's row stands
                    ['time' => '2026-11-01T11:00', 'event' => 'Service restored'],
                ],
                'action_items' => [['item' => 'add regression CASE', 'due_date' => '', 'verification' => '', 'owner' => '']],
            ]),
            'model' => 'm',
        ])]);

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('pasteText', 'timeline')
            ->call('extractFromSource')
            ->assertHasNoErrors();

        $this->runExtraction($component);

        $component->assertSet('step', 2);

        // Text-matched rows are kept (the draft's own shape wins); unseen
        // rows are appended — position is never used.
        $this->assertSame([
            ['time' => '2026-11-01T10:00', 'event' => 'Alert fired'],
            ['time' => '', 'event' => 'Investigator called'],
            ['time' => '2026-11-01T11:00', 'event' => 'Service restored'],
        ], $component->get('chronology'));

        $this->assertSame([
            ['item' => 'Add regression case', 'due_date' => '2026-11-20T09:00', 'verification' => '', 'owner' => 'Budi'],
        ], $component->get('actionItems'));
    }

    public function test_an_unparseable_ai_reply_shows_an_error_and_stays_on_step_1(): void
    {
        [, , $plain] = $this->makeLink();

        Http::fake(['brain.test/internal/run' => Http::response(['ok' => true, 'output' => 'no JSON here at all', 'model' => 'm'])]);

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('pasteText', 'x')
            ->call('extractFromSource')
            ->assertHasNoErrors();

        $this->runExtraction($component);

        $component->assertSet('step', 1)
            ->assertSet('extractError', 'The AI reply could not be read — try again.')
            ->assertSee('could not be read');
    }

    public function test_a_successful_extraction_persists_the_shared_draft(): void
    {
        [$request, , $plain] = $this->makeLink();

        Http::fake(['brain.test/internal/run' => Http::response([
            'ok' => true,
            'output' => json_encode(['header' => ['incident_name' => 'DB timeout']]),
            'model' => 'm',
        ])]);

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('email', 'a@x.com')
            ->set('pasteText', 'timeline')
            ->call('extractFromSource')
            ->assertHasNoErrors();

        $this->runExtraction($component);

        $request->refresh();
        $this->assertSame('DB timeout', $request->answers['header']['incident_name'] ?? null);
        $this->assertSame('a@x.com', $request->draft_updated_by_email);
        $this->assertNotNull($request->draft_updated_at, 'extraction persists the draft so a teammate continues it');
    }

    public function test_a_filled_answer_wins_over_the_step1_identity_fields(): void
    {
        [$request, , $plain] = $this->makeLink();

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain]);
        $this->fillRequired($component); // fills header.name + header.job_role

        $component->set('name', 'Alice Submitter')
            ->set('consentAccepted', true)
            ->set('consentEmail', 'a@x.com')
            ->call('submit')
            ->assertHasNoErrors();

        $request->refresh();
        $this->assertSame('Alice Submitter', $request->submitter_name);
        $this->assertSame('value for header.name', $request->answers['header']['name'], 'a filled answer beats the step-1 name');
        $this->assertSame('value for header.job_role', $request->answers['header']['job_role']);

        // The reverse: a BLANK answer falls back to the step-1 input. name /
        // job_role must not be required for this case, or the blank blocks
        // the submit itself.
        \App\Models\Setting::set(InvestigationFormSettings::KEY_REQUIRED_FIELDS, ['header.incident_name', 'chronology']);
        [, $recipient2, $plain2] = $this->makeLink();
        $component2 = Livewire::test(InvestigationFormIntake::class, ['token' => $plain2]);
        $this->fillRequired($component2);
        foreach (['header.name', 'header.job_role'] as $key) {
            [$section, $field] = explode('.', $key, 2);
            $component2->set("answers.{$section}.{$field}", '');
        }

        $component2->set('name', 'Alice Submitter')
            ->set('jobRole', 'Backend engineer')
            ->set('consentAccepted', true)
            ->set('consentEmail', mb_strtolower(trim((string) $recipient2->email)))
            ->call('submit')
            ->assertHasNoErrors();

        $request2 = $recipient2->request;
        $request2->refresh();
        $this->assertSame('Alice Submitter', $request2->answers['header']['name'], 'blank answer falls back to step-1 name');
        $this->assertSame('Backend engineer', $request2->answers['header']['job_role']);
    }

    public function test_extraction_dispatches_to_the_queue_and_waits_instead_of_running_inline(): void
    {
        [$request, , $plain] = $this->makeLink();

        // No brain fake on purpose: if the extraction ran inline the test
        // would fail (unrouted connection) — the whole point is it must not.
        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('email', 'a@x.com')
            ->set('pasteText', 'timeline')
            ->call('extractFromSource')
            ->assertHasNoErrors()
            ->assertSet('extractionRunning', true)
            ->assertSet('step', 1);

        $request->refresh();
        $this->assertSame('running', $request->extraction_status);
        $this->assertNotNull($request->extraction_started_at);

        Queue::assertPushed(ExtractInvestigationFormSource::class, 1);
    }

    public function test_re_dispatch_is_refused_while_an_extraction_is_running(): void
    {
        [, , $plain] = $this->makeLink();

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('pasteText', 'first')
            ->call('extractFromSource')
            ->assertHasNoErrors();

        $component->set('pasteText', 'second')
            ->call('extractFromSource')
            ->assertHasErrors(['notionUrl']);

        $this->assertStringContainsString('already running', $component->errors()->first('notionUrl'));
        Queue::assertPushed(ExtractInvestigationFormSource::class, 1);
    }

    public function test_a_stale_running_extraction_is_marked_failed_so_the_user_can_retry(): void
    {
        [$request, , $plain] = $this->makeLink();

        // A worker died without failed() firing: running, started 20 min ago.
        $request->update([
            'extraction_status' => 'running',
            'extraction_started_at' => now()->subMinutes(20),
        ]);

        Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->assertSet('extractionRunning', false) // stale → not "running" for the UI
            ->call('pollExtraction')
            ->assertSet('extractError', 'Extraction took too long — try again.')
            ->assertSet('step', 1);

        $request->refresh();
        $this->assertSame('failed', $request->extraction_status);
    }

    public function test_a_reload_mid_extraction_resumes_polling_and_applies_the_result(): void
    {
        [$request, , $plain] = $this->makeLink();

        Http::fake(['brain.test/internal/run' => Http::response([
            'ok' => true,
            'output' => json_encode(['header' => ['incident_name' => 'DB timeout']]),
            'model' => 'm',
        ])]);

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('pasteText', 'timeline')
            ->call('extractFromSource');

        // The user reloads the page while the job is queued/running.
        $reloaded = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->assertSet('extractionRunning', true);

        // The job lands; the reloaded page's next poll applies it.
        $jobs = Queue::pushed(ExtractInvestigationFormSource::class);
        $jobs->last()->handle();

        $reloaded->call('pollExtraction')
            ->assertSet('extractionRunning', false)
            ->assertSet('step', 2)
            ->assertSet('answers.header.incident_name', 'DB timeout');

        $request->refresh();
        $this->assertSame('done', $request->extraction_status);
    }

    public function test_the_ready_email_is_queued_on_success_and_skipped_on_failure(): void
    {
        Mail::fake();

        [$request, , $plain] = $this->makeLink();

        // One fake with a counter — a second Http::fake on the same URL
        // MERGES stubs and the first keeps winning (pinned in this file's
        // earlier round).
        $calls = 0;
        Http::fake(['brain.test/internal/run' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response([
                    'ok' => true,
                    'output' => json_encode(['header' => ['incident_name' => 'DB timeout']]),
                    'model' => 'm',
                ])
                : Http::response(['ok' => true, 'output' => 'no JSON here', 'model' => 'm']);
        }]);

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('email', 'a@x.com')
            ->set('pasteText', 'timeline')
            ->call('extractFromSource');

        $this->runExtraction($component);

        Mail::assertQueued(InvestigationFormRequestMail::class, 1);

        // A failed extraction sends no email — the link stays retryable.
        [$request2, , $plain2] = $this->makeLink();

        $component2 = Livewire::test(InvestigationFormIntake::class, ['token' => $plain2])
            ->set('email', 'b@x.com')
            ->set('pasteText', 'timeline')
            ->call('extractFromSource');

        $this->runExtraction($component2);

        Mail::assertQueued(InvestigationFormRequestMail::class, 1); // still just the one success mail
    }

    public function test_a_revoked_request_is_never_extracted(): void
    {
        [$request, , $plain] = $this->makeLink();

        $component = Livewire::test(InvestigationFormIntake::class, ['token' => $plain])
            ->set('pasteText', 'timeline')
            ->call('extractFromSource');

        $request->update(['status' => InvestigationFormRequest::STATUS_REVOKED]);

        $jobs = Queue::pushed(ExtractInvestigationFormSource::class);
        $jobs->last()->handle();

        $request->refresh();
        // Not failed as "try again" (the form is closed) — but not left
        // 'running' either, or the poller would spin on a dead status.
        $this->assertSame('failed', $request->extraction_status);
        $this->assertSame('This form is no longer open.', $request->extraction_result['error']);
    }

    private function pendingId($component): int
    {
        /** @var InvestigationFormRequest $request */
        $request = $component->get('formRequest');

        return $request->id;
    }
}
