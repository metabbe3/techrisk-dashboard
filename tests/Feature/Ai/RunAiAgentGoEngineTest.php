<?php

namespace Tests\Feature\Ai;

use App\Enums\AiAgentRunStatus;
use App\Jobs\Ai\RunAiAgentJob;
use App\Models\AiAgent;
use App\Models\AiAgentRun;
use App\Models\AiUsageLog;
use App\Services\Ai\AiAgentMemoryService;
use App\Services\Ai\AiTextService;
use App\Services\Ai\ChatContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunAiAgentGoEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.base_url' => 'http://gateway.test',
            'ai.api_key' => 'test-key',
            'ai.go_agent.base_url' => 'http://go.test',
            'ai.go_agent.token' => 'shared-secret',
        ]);
    }

    private function makeAgent(array $overrides = []): AiAgent
    {
        return AiAgent::create(array_merge([
            'name' => 'Go Agent',
            'instructions' => 'List open incidents.',
            'frequency' => 'Manual',
            'engine' => 'go',
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

    private function handle(AiAgent $agent, AiAgentRun $run): void
    {
        (new RunAiAgentJob($agent->id, $run->id))
            ->handle(app(AiTextService::class), app(ChatContextService::class), app(AiAgentMemoryService::class));
    }

    public function test_go_success_stores_output_tokens_and_logs_usage(): void
    {
        Http::fake(['go.test/internal/run' => Http::response([
            'ok' => true,
            'output' => '3 open incidents, 1 P1.',
            'model' => 'glm-5.3',
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 20, 'total_tokens' => 60],
            'response_time_ms' => 1500,
            'delivered' => true,
        ])]);

        $agent = $this->makeAgent();
        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Completed, $run->status);
        $this->assertSame('3 open incidents, 1 P1.', $run->output);
        $this->assertSame('glm-5.3', $run->model);
        $this->assertSame(60, $run->total_tokens);
        $this->assertSame(1500, $run->response_time_ms);

        // No DingTalk target on this agent → delivery columns stay empty.
        $this->assertNull($run->delivery_status);
        $this->assertNull($run->delivery_error);

        // Go runs log their own usage row (the php path logs inside generate()).
        $this->assertSame(1, AiUsageLog::where('field_type', 'agent')->count());
        $log = AiUsageLog::where('field_type', 'agent')->first();
        $this->assertTrue($log->success);
        $this->assertSame(60, (int) $log->total_tokens);
        $this->assertSame('go', $log->metadata['engine'] ?? null);
        $this->assertSame($agent->id, $log->metadata['agent_id'] ?? null);

        // The POST carried the auth header, assembled prompts, and task mode.
        // The instructions ride in `system`; `text` is the runtime user message.
        Http::assertSent(fn ($request) => $request->hasHeader('X-Agent-Token', 'shared-secret')
            && $request['mode'] === 'task'
            && ($request['system'] ?? '') === 'List open incidents.'
            && str_contains($request['text'] ?? '', 'Current date/time')
            && ! array_key_exists('model', $request->data())
        );
    }

    public function test_transport_failure_marks_run_failed_and_logs_usage(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $agent = $this->makeAgent();
        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Failed, $run->status);
        $this->assertStringContainsString('Go agent unreachable', (string) $run->error_message);
        $this->assertNull($run->output);

        // The failed request is still visible in usage logs.
        $log = AiUsageLog::where('field_type', 'agent')->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
    }

    public function test_token_mismatch_401_marks_run_failed(): void
    {
        Http::fake(['go.test/internal/run' => Http::response(['error' => 'unauthorized'], 401)]);

        $agent = $this->makeAgent();
        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Failed, $run->status);
        $this->assertStringContainsString('X-Agent-Token mismatch', (string) $run->error_message);
    }

    public function test_missing_token_fails_fast_without_any_http_call(): void
    {
        config(['ai.go_agent.token' => null]);
        Http::fake();

        $agent = $this->makeAgent();
        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        Http::assertNothingSent();

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Failed, $run->status);
        $this->assertStringContainsString('not configured', (string) $run->error_message);
    }

    public function test_static_engine_delivers_text_without_any_llm_call(): void
    {
        $urls = [];
        Http::fake(function ($request) use (&$urls) {
            $urls[] = $request->url();

            if (str_contains($request->url(), 'internal/run')) {
                return Http::response(['ok' => true, 'output' => 'Reminder: standup', 'model' => '', 'delivered' => true]);
            }

            return Http::response([], 500);
        });

        $agent = $this->makeAgent([
            'engine' => 'static',
            'instructions' => 'Reminder: standup',
            'dingtalk_conversation_type' => '1',
            'dingtalk_conversation_id' => 'cid-1',
            'dingtalk_staff_id' => 'staff-9',
        ]);
        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        // Exactly one HTTP call — the internal one. No gateway LLM call.
        $this->assertSame(['http://go.test/internal/run'], $urls);

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Completed, $run->status);
        $this->assertSame('Reminder: standup', $run->output);
        $this->assertSame('sent', $run->delivery_status);

        // Static makes no model call — no usage row.
        $this->assertSame(0, AiUsageLog::where('field_type', 'agent')->count());

        // Message mode: raw text + conversation binding, no system prompt.
        Http::assertSent(fn ($request) => $request['mode'] === 'message'
            && $request['text'] === 'Reminder: standup'
            && $request['conversation_type'] === '1'
            && $request['conversation_id'] === 'cid-1'
            && $request['staff_id'] === 'staff-9'
            && ! array_key_exists('system', $request->data())
        );
    }

    public function test_delivery_failure_is_recorded_but_does_not_fail_the_run(): void
    {
        Http::fake(['go.test/internal/run' => Http::response([
            'ok' => true,
            'output' => 'done',
            'delivered' => false,
            'delivery_error' => 'DingTalk send failed: 500',
        ])]);

        $agent = $this->makeAgent([
            'dingtalk_conversation_type' => '2',
            'dingtalk_conversation_id' => 'cid-2',
        ]);
        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Completed, $run->status);
        $this->assertSame('done', $run->output);
        $this->assertSame('failed', $run->delivery_status);
        $this->assertSame('DingTalk send failed: 500', $run->delivery_error);
    }

    public function test_require_json_go_run_gets_one_repair_round(): void
    {
        Http::fake([
            'go.test/internal/run' => Http::sequence()
                ->push(['ok' => true, 'output' => 'not json at all', 'usage' => ['total_tokens' => 5]])
                ->push(['ok' => true, 'output' => '{"open_incidents": 3}', 'usage' => ['total_tokens' => 5]]),
        ]);

        $agent = $this->makeAgent(['require_json' => true]);
        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Completed, $run->status);
        $this->assertSame('{"open_incidents": 3}', $run->output);

        // The repair POST fed the parse error back to the engine.
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'not valid JSON'));
    }

    public function test_once_agent_disables_after_success(): void
    {
        Http::fake(['go.test/internal/run' => Http::response(['ok' => true, 'output' => 'done'])]);

        $agent = $this->makeAgent(['frequency' => 'Once', 'run_at' => now()->subMinute()]);
        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Completed, $run->status);

        $agent->refresh();
        $this->assertFalse($agent->enabled);
    }

    public function test_once_agent_failure_rearms_for_bounded_retry(): void
    {
        Http::fake(['go.test/internal/run' => Http::response(['error' => 'boom'], 500)]);

        $agent = $this->makeAgent(['frequency' => 'Once', 'run_at' => now()->subMinute()]);
        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Failed, $run->status);

        $agent->refresh();
        $this->assertTrue($agent->enabled);
        $this->assertNotNull($agent->run_at);
        $this->assertTrue($agent->run_at->between(now()->addMinutes(4), now()->addMinutes(6)));
    }

    public function test_once_agent_gives_up_after_three_failed_runs(): void
    {
        Http::fake(['go.test/internal/run' => Http::response(['error' => 'boom'], 500)]);

        $agent = $this->makeAgent(['frequency' => 'Once', 'run_at' => now()->subMinute()]);

        // Two prior failed runs already recorded.
        foreach (range(1, 2) as $i) {
            AiAgentRun::create([
                'agent_id' => $agent->id,
                'status' => AiAgentRunStatus::Failed->value,
                'input' => 'x',
                'error_message' => 'prior failure',
                'requested_at' => now()->subMinutes(10 - $i),
                'completed_at' => now()->subMinutes(9 - $i),
            ]);
        }

        $run = $this->makeRun($agent);
        $this->handle($agent, $run);

        $run->refresh();
        $this->assertSame(AiAgentRunStatus::Failed, $run->status);

        $agent->refresh();
        $this->assertFalse($agent->enabled);
    }
}
