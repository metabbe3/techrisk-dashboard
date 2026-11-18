<?php

namespace Tests\Feature\Api;

use App\Models\AiAgent;
use App\Models\AiAgentRun;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AgentTurnReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $this->token = $user->createToken('test-token')->plainTextToken;
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function reportTurn(array $overrides = [])
    {
        return $this->withHeaders($this->headers())
            ->postJson('/api/v1/agent-runs', array_merge([
                'conversation_id' => 'cid-1',
                'conversation_type' => '1',
                'staff_id' => 'staff-1',
                'nick' => 'Andi',
                'user_text' => 'how many open P1s?',
                'assistant_text' => 'There are 3 open P1 incidents.',
                'model' => 'glm-5.3',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130],
                'response_time_ms' => 2400,
            ], $overrides));
    }

    public function test_turn_report_creates_run_and_usage_log(): void
    {
        $response = $this->reportTurn();

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Turn recorded.');
        $runId = $response->json('data.run_id');

        $run = AiAgentRun::find($runId);
        $this->assertSame(AiAgent::CHAT_TURNS_AGENT_ID, $run->agent_id);
        $this->assertSame('Completed', $run->status->value);
        $this->assertSame('how many open P1s?', $run->input);
        $this->assertSame('There are 3 open P1 incidents.', $run->output);
        $this->assertSame('glm-5.3', $run->model);
        $this->assertSame(130, $run->total_tokens);
        $this->assertSame(2400, $run->response_time_ms);
        $this->assertNull($run->error_message);

        $log = AiUsageLog::where('field_type', 'agent')->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->success);
        $this->assertSame(130, (int) $log->total_tokens);
        $this->assertSame('dingtalk-chat', $log->metadata['source'] ?? null);
        $this->assertSame('cid-1', $log->metadata['conversation_id'] ?? null);
    }

    public function test_turn_report_with_error_marks_run_failed(): void
    {
        $response = $this->reportTurn([
            'assistant_text' => null,
            'error' => 'LLM gateway timeout after 3 retries',
            'usage' => null,
            'model' => null,
        ]);

        $response->assertStatus(202);
        $runId = $response->json('data.run_id');

        $run = AiAgentRun::find($runId);
        $this->assertSame('Failed', $run->status->value);
        $this->assertNull($run->output);
        $this->assertSame('LLM gateway timeout after 3 retries', $run->error_message);
        $this->assertSame('dingtalk-agent', $run->model); // NOT NULL fallback when no model was answered

        $log = AiUsageLog::where('field_type', 'agent')->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
        $this->assertSame('LLM gateway timeout after 3 retries', $log->error_message);
    }

    public function test_turn_report_requires_user_text_and_conversation(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/agent-runs', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['conversation_id', 'user_text']);
    }

    public function test_token_without_the_agent_runs_endpoint_is_rejected(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $restricted = $user->createToken('restricted', ['*']);
        PersonalAccessToken::find($restricted->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode(['agent-schedules'])])
            ->save();

        $this->withHeader('Authorization', 'Bearer '.$restricted->plainTextToken)
            ->postJson('/api/v1/agent-runs', ['conversation_id' => 'cid-1', 'user_text' => 'hi'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_token_with_only_the_agent_runs_endpoint_is_accepted(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $allowed = $user->createToken('runs-only', ['*']);
        PersonalAccessToken::find($allowed->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode(['agent-runs'])])
            ->save();

        $this->withHeader('Authorization', 'Bearer '.$allowed->plainTextToken)
            ->postJson('/api/v1/agent-runs', ['conversation_id' => 'cid-1', 'user_text' => 'hi'])
            ->assertStatus(202);
    }
}
