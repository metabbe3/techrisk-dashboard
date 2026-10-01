<?php

namespace Tests\Feature\Ai;

use App\Enums\AiAgentRunStatus;
use App\Jobs\Ai\RunAiAgentJob;
use App\Models\AiAgent;
use App\Models\AiAgentMemory;
use App\Models\AiAgentRun;
use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Services\Ai\AiAgentMemoryService;
use App\Services\Ai\AiTextService;
use App\Services\Ai\ChatContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RunAiAgentJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.base_url' => 'http://gateway.test', 'ai.api_key' => 'test-key']);
    }

    private function makeAgent(array $overrides = []): AiAgent
    {
        return AiAgent::create(array_merge([
            'name' => 'Daily Digest',
            'instructions' => 'Summarize today\'s incidents into a short digest.',
            'frequency' => 'Manual',
        ], $overrides));
    }

    private function makeRun(AiAgent $agent): AiAgentRun
    {
        return AiAgentRun::create([
            'agent_id' => $agent->id,
            'status' => AiAgentRunStatus::Pending->value,
            'input' => $agent->instructions,
            'requested_at' => now(),
        ]);
    }

    public function test_completed_run_saves_output_tokens_and_logs_usage(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Digest: 3 incidents, all resolved.']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ])]);

        $agent = $this->makeAgent();
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Completed, $run->status);
        $this->assertSame('Digest: 3 incidents, all resolved.', $run->output);
        $this->assertSame(15, $run->total_tokens);
        $this->assertNotNull($run->response_time_ms);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->completed_at);

        $this->assertSame(1, AiUsageLog::where('field_type', 'agent')->count());
        $log = AiUsageLog::where('field_type', 'agent')->first();
        $this->assertTrue($log->success);
        $this->assertSame(15, (int) $log->total_tokens);
        $this->assertNull($log->user_id);
        $this->assertSame($agent->id, $log->metadata['agent_id'] ?? null);
    }

    public function test_gateway_failure_marks_run_failed_and_logs_failure(): void
    {
        Http::fake(fn () => Http::response(['error' => ['message' => 'boom']], 500));

        $agent = $this->makeAgent();
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Failed, $run->status);
        $this->assertNotNull($run->error_message);
        $this->assertNull($run->output);

        $log = AiUsageLog::where('field_type', 'agent')->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
    }

    public function test_null_model_falls_through_to_default_model_setting(): void
    {
        AiSetting::set('default_model', 'FAST-MODEL');
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $agent = $this->makeAgent(['model' => null]);
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Http::assertSent(fn ($request) => str_contains($request->body(), 'FAST-MODEL'));

        $run->refresh();
        $this->assertSame('FAST-MODEL', $run->model);
    }

    public function test_agent_model_override_is_sent_when_set(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $agent = $this->makeAgent(['model' => 'deepseek-v4-pro']);
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Http::assertSent(fn ($request) => str_contains($request->body(), 'deepseek-v4-pro'));
    }

    public function test_run_is_skipped_when_another_run_holds_the_lock(): void
    {
        Http::fake();
        $agent = $this->makeAgent();
        $run = $this->makeRun($agent);

        $outside = Cache::lock("ai-agent-run:{$agent->id}", 60);
        $outside->get();

        try {
            (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));
        } finally {
            $outside->release();
        }

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Failed, $run->status);
        $this->assertStringContainsString('Skipped', (string) $run->error_message);
        Http::assertNothingSent();
    }

    public function test_include_context_appends_dashboard_stats_and_omits_them_when_off(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $withContext = $this->makeAgent(['include_context' => true]);
        $withoutContext = $this->makeAgent();

        (new RunAiAgentJob($withContext->id, $this->makeRun($withContext)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));
        (new RunAiAgentJob($withoutContext->id, $this->makeRun($withoutContext)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Http::assertSent(fn ($request) => $request->body() !== null && str_contains($request->body(), '## Dashboard context'));
        $this->assertSame(1,
            collect(Http::recorded())->filter(fn ($pair) => str_contains((string) $pair[0]->body(), '## Dashboard context'))->count()
        );
    }

    public function test_notify_email_receives_output_on_success(): void
    {
        config(['mail.default' => 'array']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Digest: 3 incidents, all resolved.']]],
        ])]);

        $agent = $this->makeAgent(['notify_email' => 'ops@example.test']);
        (new RunAiAgentJob($agent->id, $this->makeRun($agent)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $messages = $transport->messages();
        $this->assertCount(1, $messages);

        $message = $messages[0]->getOriginalMessage();
        $this->assertSame('ops@example.test', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString($agent->name, $message->getSubject());
        $this->assertStringContainsString('completed', $message->getSubject());
        $this->assertStringContainsString('Digest: 3 incidents', $message->getTextBody());
    }

    public function test_notify_email_receives_error_when_run_fails(): void
    {
        config(['mail.default' => 'array']);
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $agent = $this->makeAgent(['notify_email' => 'ops@example.test']);
        (new RunAiAgentJob($agent->id, $this->makeRun($agent)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $this->assertStringContainsString('failed', $message->getSubject());
        $this->assertStringContainsString('Agent run failed', $message->getTextBody());
    }

    public function test_no_email_is_sent_without_notify_email(): void
    {
        config(['mail.default' => 'array']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $agent = $this->makeAgent();
        (new RunAiAgentJob($agent->id, $this->makeRun($agent)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_memory_read_block_and_protocol_included_when_include_memory_on(): void
    {
        $writer = $this->makeAgent(['include_memory' => true]);
        AiAgentMemory::create(['agent_id' => $writer->id, 'kind' => 'Lesson', 'content' => 'Prior lesson from the shared memory']);

        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        (new RunAiAgentJob($writer->id, $this->makeRun($writer)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($body, '## Shared agent memory')
                && str_contains($body, 'Prior lesson from the shared memory')
                && str_contains($body, '## Memory protocol');
        });
    }

    public function test_memory_block_absent_when_include_memory_off(): void
    {
        $agent = $this->makeAgent();
        $other = $this->makeAgent(['name' => 'Other']);
        AiAgentMemory::create(['agent_id' => $other->id, 'kind' => 'Note', 'content' => 'should not be injected']);

        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        (new RunAiAgentJob($agent->id, $this->makeRun($agent)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $bodies = collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]->body());
        $this->assertSame(1, $bodies->reject(fn ($body) => str_contains($body, '## Shared agent memory') || str_contains($body, '## Memory protocol'))->count());
    }

    public function test_memory_rows_created_from_output_section_on_success(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => "Digest done.\n\n## Memory\n- LESSON: Cluster found\n- OUTCOME: Digest delivered"]]],
        ])]);

        $agent = $this->makeAgent(['include_memory' => true]);
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $rows = AiAgentMemory::where('agent_id', $agent->id)->orderBy('kind')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Lesson', $rows[0]->kind->value);
        $this->assertSame('Cluster found', $rows[0]->content);
        $this->assertSame($run->id, $rows[0]->run_id);
    }

    public function test_no_memory_rows_when_run_fails(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $agent = $this->makeAgent(['include_memory' => true]);
        (new RunAiAgentJob($agent->id, $this->makeRun($agent)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $this->assertSame(0, AiAgentMemory::count());
    }

    public function test_chain_dispatches_enabled_dependents_with_provenance(): void
    {
        Queue::fake();
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $upstream = $this->makeAgent();
        $dependent = $this->makeAgent(['name' => 'Follow-up', 'depends_on_agent_id' => $upstream->id]);

        $run = $this->makeRun($upstream);
        (new RunAiAgentJob($upstream->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Queue::assertPushed(RunAiAgentJob::class, 1);

        $childRun = AiAgentRun::where('agent_id', $dependent->id)->first();
        $this->assertNotNull($childRun);
        $this->assertSame($run->id, $childRun->triggered_by_run_id);
        $this->assertSame(AiAgentRunStatus::Pending, $childRun->status);
    }

    public function test_chain_skipped_on_failed_run(): void
    {
        Queue::fake();
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $upstream = $this->makeAgent();
        $this->makeAgent(['name' => 'Follow-up', 'depends_on_agent_id' => $upstream->id]);

        (new RunAiAgentJob($upstream->id, $this->makeRun($upstream)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Queue::assertNothingPushed();
    }

    public function test_chain_skips_disabled_dependents(): void
    {
        Queue::fake();
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $upstream = $this->makeAgent();
        $this->makeAgent(['name' => 'Disabled follow-up', 'depends_on_agent_id' => $upstream->id, 'enabled' => false]);

        (new RunAiAgentJob($upstream->id, $this->makeRun($upstream)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Queue::assertNothingPushed();
    }

    public function test_org_block_injected_only_when_some_agent_has_reports_to(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $boss = $this->makeAgent(['name' => 'Boss']);
        $this->makeAgent(['name' => 'Sub', 'reports_to_agent_id' => $boss->id]);

        // First run: hierarchy exists -> org block present.
        (new RunAiAgentJob($boss->id, $this->makeRun($boss)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        // Flatten the org: no reports_to anywhere -> org block absent.
        AiAgent::query()->update(['reports_to_agent_id' => null]);
        $sub = AiAgent::where('name', 'Sub')->first();
        (new RunAiAgentJob($sub->id, $this->makeRun($sub)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $withOrg = collect(Http::recorded())->filter(fn ($pair) => str_contains((string) $pair[0]->body(), '## Agent organization'))->count();
        $this->assertSame(1, $withOrg);
    }

    public function test_upstream_output_injected_into_dependent_run(): void
    {
        config(['ai.agents.upstream_inject_limit' => 50]);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $upstream = $this->makeAgent(['name' => 'Upstream Agent']);
        $upstreamRun = AiAgentRun::create([
            'agent_id' => $upstream->id,
            'status' => AiAgentRunStatus::Completed->value,
            'input' => 'x',
            'output' => 'Full digest text '.str_repeat('x', 200),
            'requested_at' => now(),
            'completed_at' => now(),
        ]);

        $dependent = $this->makeAgent(['name' => 'Dependent']);
        $run = AiAgentRun::create([
            'agent_id' => $dependent->id,
            'status' => AiAgentRunStatus::Pending->value,
            'input' => 'x',
            'requested_at' => now(),
            'triggered_by_run_id' => $upstreamRun->id,
        ]);

        (new RunAiAgentJob($dependent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($body, '## Upstream result from \"Upstream Agent\"')
                && str_contains($body, 'Full digest text')
                && substr_count($body, 'x') < 200; // truncated at the 50-char limit
        });
    }

    public function test_no_upstream_block_on_manual_run(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $agent = $this->makeAgent();
        (new RunAiAgentJob($agent->id, $this->makeRun($agent)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $this->assertSame(0, collect(Http::recorded())->filter(fn ($pair) => str_contains((string) $pair[0]->body(), '## Upstream result'))->count());
    }

    public function test_transient_gateway_failure_is_retried(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'boom']], 500)
            ->push(['choices' => [['message' => ['content' => 'recovered output']]]]);

        $agent = $this->makeAgent();
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Completed, $run->status);
        $this->assertSame('recovered output', $run->output);
    }

    public function test_expected_output_block_injected_when_set(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $withContract = $this->makeAgent(['expected_output' => '3 bullets, one-line verdict at the end']);
        $without = $this->makeAgent(['name' => 'No Contract']);

        (new RunAiAgentJob($withContract->id, $this->makeRun($withContract)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));
        (new RunAiAgentJob($without->id, $this->makeRun($without)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $withBlock = collect(Http::recorded())->filter(fn ($pair) => str_contains((string) $pair[0]->body(), '## Expected output'))->count();
        $this->assertSame(1, $withBlock);
    }

    public function test_require_json_passes_on_valid_json(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '{"verdict": "low risk", "open": 7}']]],
        ])]);

        $agent = $this->makeAgent(['require_json' => true]);
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Completed, $run->status);
        $this->assertSame(1, count(Http::recorded())); // no repair round needed
    }

    public function test_require_json_repairs_once_then_fails_on_second_invalid(): void
    {
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => 'not json at all']]]])
            ->push(['choices' => [['message' => ['content' => 'still not json']]]]);

        $agent = $this->makeAgent(['require_json' => true]);
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Failed, $run->status);
        $this->assertSame('Output failed JSON validation after retry.', $run->error_message);
        $this->assertSame(2, count(Http::recorded())); // original + one repair
        $this->assertStringContainsString('not valid JSON', (string) Http::recorded()[1][0]->body());
    }

    public function test_require_json_repair_succeeds_on_second_attempt(): void
    {
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => 'oops prose']]]])
            ->push(['choices' => [['message' => ['content' => '{"fixed": true}']]]]);

        $agent = $this->makeAgent(['require_json' => true]);
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Completed, $run->status);
        $this->assertSame('{"fixed": true}', $run->output);
    }

    public function test_attached_files_block_injected_and_budgeted(): void
    {
        config(['ai.agents.file_inject_limit' => 20]);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $agent = $this->makeAgent();
        \App\Models\AiAgentFile::create([
            'agent_id' => $agent->id,
            'filename' => 'notes.txt',
            'path' => 'agent-files/x.txt',
            'mime' => 'text/plain',
            'size' => 100,
            'extracted_text' => '0123456789012345678901234567890123456789', // 40 chars, budget 20
        ]);

        (new RunAiAgentJob($agent->id, $this->makeRun($agent)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($body, '## Attached files')
                && str_contains($body, '### notes.txt')
                && str_contains($body, '01234567890123456789...') // truncated to the 20-char budget
                && ! str_contains($body, '012345678901234567890'); // full 40 chars never sent
        });
    }

    public function test_attached_files_without_extracted_text_are_skipped(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $agent = $this->makeAgent();
        \App\Models\AiAgentFile::create([
            'agent_id' => $agent->id,
            'filename' => 'broken.pdf',
            'path' => 'agent-files/y.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
            'extracted_text' => null,
        ]);

        (new RunAiAgentJob($agent->id, $this->makeRun($agent)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $this->assertSame(0, collect(Http::recorded())->filter(fn ($pair) => str_contains((string) $pair[0]->body(), '## Attached files'))->count());
    }

    public function test_investigation_docs_block_injected_when_enabled(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $this->mock(\App\Services\Markdown\DocumentConverterService::class)
            ->shouldReceive('convert')
            ->andReturn('extracted investigation content');

        $withDocs = $this->makeAgent(['include_documents' => true]);
        $without = $this->makeAgent(['name' => 'No Docs']);

        \App\Models\InvestigationDocument::factory()->create(['original_filename' => 'report.pdf']);

        (new RunAiAgentJob($withDocs->id, $this->makeRun($withDocs)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));
        (new RunAiAgentJob($without->id, $this->makeRun($without)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), '## Investigation documents')
            && str_contains((string) $request->body(), '### report.pdf')
            && str_contains((string) $request->body(), 'extracted investigation content'));
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains((string) $pair[0]->body(), '## Investigation documents'))->count());
    }

    public function test_include_corpus_appends_catalog_and_omits_when_off(): void
    {
        Storage::fake('local');
        \App\Models\Incident::factory()->create([
            'no' => '2040_IN_030',
            'title' => 'Fraud payout spike',
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_date' => '2040-01-15 10:00:00',
            'stop_bleeding_at' => '2040-01-15 12:00:00',
        ]);
        app(\App\Services\Markdown\IncidentMarkdownCorpusService::class)->refresh();

        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $withCorpus = $this->makeAgent(['include_corpus' => true]);
        $without = $this->makeAgent(['name' => 'No Corpus']);

        (new RunAiAgentJob($withCorpus->id, $this->makeRun($withCorpus)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));
        (new RunAiAgentJob($without->id, $this->makeRun($without)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), '## Incident catalog')
            && str_contains((string) $request->body(), '2040_IN_030')
            && str_contains((string) $request->body(), 'Fraud payout spike'));
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains((string) $pair[0]->body(), '## Incident catalog'))->count());
    }

    public function test_include_corpus_skips_block_when_corpus_never_built(): void
    {
        // No refresh() — corpus has never been built. The run must still
        // complete; the block is simply absent.
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $agent = $this->makeAgent(['include_corpus' => true]);
        $run = $this->makeRun($agent);

        (new RunAiAgentJob($agent->id, $run->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        $this->assertSame(0, collect(Http::recorded())->filter(fn ($pair) => str_contains((string) $pair[0]->body(), '## Incident catalog'))->count());
        $this->assertSame(AiAgentRunStatus::Completed, $run->refresh()->status);
    }

    public function test_include_corpus_respects_inject_limit(): void
    {
        Storage::fake('local');
        \App\Models\Incident::factory()->create([
            'no' => '2040_IN_031',
            'title' => 'Exploding payment gateway fraud incident',
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_date' => '2040-01-16 10:00:00',
            'stop_bleeding_at' => '2040-01-16 12:00:00',
        ]);
        app(\App\Services\Markdown\IncidentMarkdownCorpusService::class)->refresh();
        config(['ai.agents.corpus_inject_limit' => 40]);

        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $agent = $this->makeAgent(['include_corpus' => true]);
        (new RunAiAgentJob($agent->id, $this->makeRun($agent)->id))->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));

        // Catalog cut at 40 chars: the severity marker past the title is gone.
        $body = (string) collect(Http::recorded())->first()[0]->body();
        $this->assertStringContainsString('## Incident catalog', $body);
        $this->assertStringNotContainsString('(P1', $body);
    }
}
