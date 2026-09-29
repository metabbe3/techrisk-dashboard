<?php

namespace Tests\Feature\Console;

use App\Enums\AiAgentRunStatus;
use App\Jobs\Ai\RunAiAgentJob;
use App\Models\AiAgent;
use App\Models\AiAgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDueAgentsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(array $overrides = []): AiAgent
    {
        return AiAgent::create(array_merge([
            'name' => 'Scheduled Agent',
            'instructions' => 'Do the thing.',
            'frequency' => 'Cron',
            'cron_expression' => '* * * * *', // due any minute
            'enabled' => true,
        ], $overrides));
    }

    public function test_dispatches_due_agent_and_stamps_last_run(): void
    {
        Queue::fake();
        $agent = $this->makeAgent();

        $this->artisan('ai:dispatch-due-agents')->assertSuccessful();

        Queue::assertPushed(RunAiAgentJob::class, fn ($job) => $job->agentId === $agent->id);

        $run = AiAgentRun::where('agent_id', $agent->id)->first();
        $this->assertNotNull($run);
        $this->assertSame(AiAgentRunStatus::Pending, $run->status);
        $this->assertSame($agent->instructions, $run->input);

        $agent->refresh();
        $this->assertNotNull($agent->last_run_at);
    }

    public function test_does_not_dispatch_never_due_cron(): void
    {
        Queue::fake();
        $this->makeAgent(['cron_expression' => '0 0 31 2 *']); // Feb 31 — never

        $this->artisan('ai:dispatch-due-agents')->assertSuccessful();

        Queue::assertNotPushed(RunAiAgentJob::class);
        $this->assertSame(0, AiAgentRun::count());
    }

    public function test_does_not_dispatch_disabled_agent(): void
    {
        Queue::fake();
        $this->makeAgent(['enabled' => false]);

        $this->artisan('ai:dispatch-due-agents')->assertSuccessful();

        Queue::assertNotPushed(RunAiAgentJob::class);
    }

    public function test_same_minute_guard_prevents_double_dispatch(): void
    {
        Queue::fake();
        $this->makeAgent(['last_run_at' => now()->subSeconds(30)]);

        $this->artisan('ai:dispatch-due-agents')->assertSuccessful();

        Queue::assertNotPushed(RunAiAgentJob::class);
    }

    public function test_stale_running_run_is_marked_failed(): void
    {
        Queue::fake();
        $agent = $this->makeAgent(['frequency' => 'Manual']);

        AiAgentRun::create([
            'agent_id' => $agent->id,
            'status' => AiAgentRunStatus::Running->value,
            'input' => 'old',
            'started_at' => now()->subMinutes(20),
            'requested_at' => now()->subMinutes(21),
        ]);

        $this->artisan('ai:dispatch-due-agents')->assertSuccessful();

        $run = AiAgentRun::first();
        $this->assertSame(AiAgentRunStatus::Failed, $run->status);
        $this->assertStringContainsString('stale sweep', (string) $run->error_message);
    }
}
