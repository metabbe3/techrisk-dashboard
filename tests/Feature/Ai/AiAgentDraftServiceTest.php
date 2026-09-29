<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageLog;
use App\Services\Ai\AiAgentDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAgentDraftServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.base_url' => 'http://gateway.test', 'ai.api_key' => 'test-key']);
    }

    private function fakeAiResponse(string $content): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => $content]]],
        ])]);
    }

    public function test_drafts_agent_from_natural_language(): void
    {
        $this->fakeAiResponse(json_encode([
            'name' => 'Morning Critical Digest',
            'description' => 'Daily morning digest of critical incidents.',
            'instructions' => 'You are a technical-risk operations assistant. Produce a digest of critical incidents.',
            'include_context' => true,
            'schedule_type' => 'daily',
            'run_time' => '8:30',
            'run_weekday' => 9,
        ]));

        $result = app(AiAgentDraftService::class)->draft('Every morning at 8:30 summarize critical incidents and email me');

        $this->assertTrue($result['success']);
        $this->assertSame('Morning Critical Digest', $result['agent']['name']);
        $this->assertSame('Daily morning digest of critical incidents.', $result['agent']['description']);
        $this->assertStringStartsWith('You are', $result['agent']['instructions']);
        $this->assertTrue($result['agent']['include_context']);
        // "8:30" zero-padded; out-of-range weekday 9 falls back to 1.
        $this->assertSame('daily', $result['agent']['schedule_type']);
        $this->assertSame('08:30', $result['agent']['run_time']);
        $this->assertSame(1, $result['agent']['run_weekday']);
    }

    public function test_fenced_json_is_extracted(): void
    {
        $this->fakeAiResponse("```json\n".json_encode([
            'name' => 'Weekly Watch',
            'description' => 'Weekly trend summary.',
            'instructions' => 'You are a technical-risk analyst. Summarize the week.',
            'include_context' => true,
            'schedule_type' => 'weekly',
            'run_time' => '09:00',
            'run_weekday' => 3,
        ])."\n```");

        $result = app(AiAgentDraftService::class)->draft('Weekly trend summary');

        $this->assertTrue($result['success']);
        $this->assertSame('weekly', $result['agent']['schedule_type']);
        $this->assertSame(3, $result['agent']['run_weekday']);
    }

    public function test_unparseable_response_returns_failure(): void
    {
        $this->fakeAiResponse('I cannot do that.');

        $result = app(AiAgentDraftService::class)->draft('make me an agent');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_invalid_schedule_type_and_time_fall_back_to_defaults(): void
    {
        $this->fakeAiResponse(json_encode([
            'name' => 'Odd Agent',
            'description' => 'd',
            'instructions' => 'You are something.',
            'include_context' => 'no',
            'schedule_type' => 'weekdays',
            'run_time' => '25:99',
            'run_weekday' => 'x',
        ]));

        $result = app(AiAgentDraftService::class)->draft('every weekday');

        $this->assertTrue($result['success']);
        $this->assertSame('manual', $result['agent']['schedule_type']);
        $this->assertSame('09:00', $result['agent']['run_time']);
        $this->assertSame(1, $result['agent']['run_weekday']);
        $this->assertFalse($result['agent']['include_context']);
    }

    public function test_oversize_fields_are_truncated(): void
    {
        $this->fakeAiResponse(json_encode([
            'name' => str_repeat('N', 300),
            'description' => str_repeat('D', 300),
            'instructions' => 'You are something.',
            'schedule_type' => 'manual',
        ]));

        $result = app(AiAgentDraftService::class)->draft('agent');

        $this->assertTrue($result['success']);
        $this->assertSame(120, mb_strlen($result['agent']['name']));
        $this->assertSame(255, mb_strlen($result['agent']['description']));
    }

    public function test_usage_is_logged_with_agent_draft_field_type(): void
    {
        $this->fakeAiResponse(json_encode([
            'name' => 'Logged',
            'description' => 'd',
            'instructions' => 'You are something.',
            'schedule_type' => 'manual',
        ]));

        app(AiAgentDraftService::class)->draft('agent');

        $this->assertTrue(
            AiUsageLog::where('field_type', 'agent_draft')->where('success', true)->exists()
        );
    }

    public function test_links_chain_fields_to_existing_agents(): void
    {
        $agentA = \App\Models\AiAgent::create(['name' => 'Chain A', 'instructions' => 'x', 'frequency' => 'Manual']);
        $this->fakeAiResponse(json_encode([
            'name' => 'Chain B',
            'description' => 'Follow-up review.',
            'instructions' => 'You are a follow-up reviewer.',
            'schedule_type' => 'manual',
            'runs_after' => $agentA->id,
            'reports_to' => $agentA->id,
            'include_memory' => true,
        ]));

        $options = [$agentA->id => 'Chain A'];
        $result = app(AiAgentDraftService::class)->draft('a follow-up that runs after Chain A', null, $options);

        $this->assertTrue($result['success']);
        $this->assertSame($agentA->id, $result['agent']['depends_on_agent_id']);
        $this->assertSame($agentA->id, $result['agent']['reports_to_agent_id']);
        $this->assertTrue($result['agent']['include_memory']);

        // The agent list rides along in the user message so the model can pick ids.
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Existing agents')
            && str_contains((string) $request->body(), "- {$agentA->id}: Chain A"));
    }

    public function test_unknown_or_missing_agent_ids_drop_to_null(): void
    {
        $agentA = \App\Models\AiAgent::create(['name' => 'Chain A', 'instructions' => 'x', 'frequency' => 'Manual']);
        $this->fakeAiResponse(json_encode([
            'name' => 'Odd Link',
            'description' => 'd',
            'instructions' => 'You are something.',
            'schedule_type' => 'manual',
            'runs_after' => 'hallucinated-agent-id',
            'reports_to' => 'Chain A', // a name, not an id — must not resolve
        ]));

        $result = app(AiAgentDraftService::class)->draft('follow up after Chain A', null, [$agentA->id => 'Chain A']);

        $this->assertTrue($result['success']);
        $this->assertNull($result['agent']['depends_on_agent_id']);
        $this->assertNull($result['agent']['reports_to_agent_id']);
        // Nothing linked -> memory defaults off.
        $this->assertFalse($result['agent']['include_memory']);
    }

    public function test_memory_defaults_on_when_linked_without_explicit_flag(): void
    {
        $agentA = \App\Models\AiAgent::create(['name' => 'Chain A', 'instructions' => 'x', 'frequency' => 'Manual']);
        $this->fakeAiResponse(json_encode([
            'name' => 'Chain B',
            'description' => 'd',
            'instructions' => 'You are something.',
            'schedule_type' => 'manual',
            'runs_after' => $agentA->id,
            // include_memory omitted entirely
        ]));

        $result = app(AiAgentDraftService::class)->draft('runs after Chain A', null, [$agentA->id => 'Chain A']);

        $this->assertTrue($result['success']);
        $this->assertSame($agentA->id, $result['agent']['depends_on_agent_id']);
        $this->assertTrue($result['agent']['include_memory']);
    }

    public function test_drafts_output_contract_when_user_specifies_format(): void
    {
        $this->fakeAiResponse(json_encode([
            'name' => 'JSON Verdict',
            'description' => 'd',
            'instructions' => 'You are something.',
            'schedule_type' => 'manual',
            'expected_output' => 'JSON object with keys verdict (low/medium/high) and open_count (int)',
            'require_json' => true,
        ]));

        $result = app(AiAgentDraftService::class)->draft('risk verdict as JSON');

        $this->assertTrue($result['success']);
        $this->assertSame('JSON object with keys verdict (low/medium/high) and open_count (int)', $result['agent']['expected_output']);
        $this->assertTrue($result['agent']['require_json']);
    }

    public function test_output_contract_defaults_blank_when_unspecified(): void
    {
        $this->fakeAiResponse(json_encode([
            'name' => 'Plain',
            'description' => 'd',
            'instructions' => 'You are something.',
            'schedule_type' => 'manual',
        ]));

        $result = app(AiAgentDraftService::class)->draft('an agent');

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['agent']['expected_output']);
        $this->assertFalse($result['agent']['require_json']);
    }

    public function test_no_agent_options_means_no_links_and_no_list(): void
    {
        $this->fakeAiResponse(json_encode([
            'name' => 'Solo',
            'description' => 'd',
            'instructions' => 'You are something.',
            'schedule_type' => 'manual',
            'runs_after' => 'some-id-the-model-invented',
        ]));

        $result = app(AiAgentDraftService::class)->draft('an agent');

        $this->assertTrue($result['success']);
        $this->assertNull($result['agent']['depends_on_agent_id']);
        $this->assertNull($result['agent']['reports_to_agent_id']);
        $this->assertFalse($result['agent']['include_memory']);

        Http::assertSent(fn ($request) => ! str_contains((string) $request->body(), 'Existing agents'));
    }
}
