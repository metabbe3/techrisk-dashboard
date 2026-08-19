<?php

namespace Tests\Unit\Services\WarRoom;

use App\Jobs\WarRoom\ProcessWarRoomAgent;
use App\Jobs\WarRoom\StartWarRoomSession;
use App\Jobs\WarRoom\SynthesizeWarRoomReport;
use App\Models\Incident;
use App\Models\User;
use App\Models\WarRoomAgentConfig;
use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\WebSearchService;
use App\Services\Markdown\IncidentMarkdownExporter;
use App\Services\Skills\SkillRoutingService;
use App\Services\WarRoom\AgentPromptBuilder;
use App\Services\WarRoom\WarRoomService;
use App\Services\WarRoom\WarRoomStreamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WarRoomServiceTest extends TestCase
{
    use RefreshDatabase;

    private WarRoomService $service;

    private IncidentMarkdownExporter $markdownExporter;

    private AgentPromptBuilder $promptBuilder;

    private WebSearchService $webSearchService;

    private AiUsageLogger $usageLogger;

    private WarRoomStreamingService $streamingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markdownExporter = Mockery::mock(IncidentMarkdownExporter::class);
        $this->promptBuilder = Mockery::mock(AgentPromptBuilder::class);
        $this->webSearchService = Mockery::mock(WebSearchService::class);
        $this->usageLogger = Mockery::mock(AiUsageLogger::class);
        $this->streamingService = Mockery::mock(WarRoomStreamingService::class);

        // Allow usageLogger to receive log calls silently (fire-and-forget in most flows)
        $this->usageLogger->shouldReceive('log')->byDefault();

        $this->app->instance(IncidentMarkdownExporter::class, $this->markdownExporter);
        $this->app->instance(AgentPromptBuilder::class, $this->promptBuilder);
        $this->app->instance(WebSearchService::class, $this->webSearchService);
        $this->app->instance(AiUsageLogger::class, $this->usageLogger);
        $this->app->instance(WarRoomStreamingService::class, $this->streamingService);

        // Mock SkillRoutingService so startSession does not call the real one
        $skillRouting = Mockery::mock(SkillRoutingService::class);
        $skillRouting->shouldReceive('selectSkillsForSession')->byDefault();
        $this->app->instance(SkillRoutingService::class, $skillRouting);

        $this->service = $this->app->make(WarRoomService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 1. createSession
    // -----------------------------------------------------------------------

    public function test_create_session_creates_session_and_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $incident = Incident::factory()->create();

        $this->markdownExporter
            ->shouldReceive('generateCompact')
            ->once()
            ->andReturn('Incident markdown context');

        $session = $this->service->createSession(
            incidentIds: [$incident->id],
            user: $user,
            selectedAgents: ['sre', 'tech_risk'],
            maxRounds: 3,
            model: 'SMART-MODEL',
        );

        $this->assertInstanceOf(WarRoomSession::class, $session);
        $this->assertEquals($user->id, $session->user_id);
        $this->assertEquals($incident->id, $session->incident_id);
        $this->assertSame('pending', $session->status);
        $this->assertSame(3, $session->max_rounds);
        $this->assertSame('SMART-MODEL', $session->model);
        $this->assertEquals(['sre', 'tech_risk'], $session->selected_agents);
        $this->assertStringContainsString('AI Retrospective:', $session->title);

        // Incident should be linked via pivot
        $this->assertTrue($session->incidents->contains($incident));

        Queue::assertPushed(StartWarRoomSession::class, 1);
        Queue::assertPushed(StartWarRoomSession::class, function ($job) use ($session) {
            return $job->session->id === $session->id;
        });
    }

    public function test_create_session_throws_when_no_valid_incidents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No valid incidents found');

        $user = User::factory()->create();

        $this->service->createSession(
            incidentIds: [99999],
            user: $user,
            selectedAgents: ['sre'],
        );
    }

    public function test_create_session_enforces_daily_session_limit(): void
    {
        config(['ai.war_room.rate_limits.max_sessions_per_user_per_day' => 2]);

        $user = User::factory()->create();
        WarRoomSession::factory()->create(['user_id' => $user->id, 'created_at' => now()]);
        WarRoomSession::factory()->create(['user_id' => $user->id, 'created_at' => now()]);

        $incident = Incident::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Daily session limit reached');

        $this->service->createSession(
            incidentIds: [$incident->id],
            user: $user,
            selectedAgents: ['sre'],
        );
    }

    public function test_create_session_enforces_active_session_limit(): void
    {
        $limit = config('ai.war_room.rate_limits.max_active_sessions_per_user', 3);

        $user = User::factory()->create();
        WarRoomSession::factory()->count($limit)->create([
            'user_id' => $user->id,
            'status' => 'running',
        ]);

        $incident = Incident::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Active session limit reached');

        $this->service->createSession(
            incidentIds: [$incident->id],
            user: $user,
            selectedAgents: ['sre'],
        );
    }

    // -----------------------------------------------------------------------
    // 2. startSession
    // -----------------------------------------------------------------------

    public function test_start_session_marks_running_and_dispatches_round(): void
    {
        Queue::fake();
        Config::set('ai.war_room.pre_analysis_enabled', false);

        $session = WarRoomSession::factory()->create([
            'status' => 'pending',
            'selected_agents' => ['sre', 'dba'],
            'current_round' => 0,
        ]);

        $this->service->startSession($session);

        $session->refresh();
        $this->assertSame('running', $session->status);
        $this->assertNotNull($session->started_at);

        // dispatchRound creates one message + one job per agent
        $messages = WarRoomMessage::where('session_id', $session->id)->get();
        $this->assertCount(2, $messages);
        $this->assertSame(1, $messages->first()->round);

        $roles = $messages->pluck('agent_role')->sort()->values()->toArray();
        $this->assertEquals(['dba', 'sre'], $roles);

        Queue::assertPushed(ProcessWarRoomAgent::class, 2);
    }

    public function test_start_session_dispatches_pre_analysis_when_enabled(): void
    {
        Queue::fake();
        Config::set('ai.war_room.pre_analysis_enabled', true);

        $session = WarRoomSession::factory()->create([
            'status' => 'pending',
            'selected_agents' => ['sre', 'dba'],
            'current_round' => 0,
        ]);

        $this->service->startSession($session);

        $session->refresh();
        $this->assertSame('running', $session->status);

        // Should dispatch RunPreAnalysis instead of agents directly
        Queue::assertPushed(\App\Jobs\WarRoom\RunPreAnalysis::class, 1);
        Queue::assertNotPushed(ProcessWarRoomAgent::class);

        // No messages created yet — they're deferred to after pre-analysis
        $messages = WarRoomMessage::where('session_id', $session->id)->get();
        $this->assertCount(0, $messages);
    }
    // -----------------------------------------------------------------------

    public function test_dispatch_round_creates_messages_for_each_agent(): void
    {
        Queue::fake();

        $session = WarRoomSession::factory()->create([
            'selected_agents' => ['sre', 'tech_risk', 'dba'],
        ]);

        $this->service->dispatchRound($session, 2);

        $messages = WarRoomMessage::where('session_id', $session->id)
            ->where('round', 2)
            ->get();

        $this->assertCount(3, $messages);

        $roles = $messages->pluck('agent_role')->sort()->values()->toArray();
        $this->assertEquals(['dba', 'sre', 'tech_risk'], $roles);

        foreach ($messages as $msg) {
            $this->assertSame('pending', $msg->status);
            $this->assertSame('assistant', $msg->role);
        }

        Queue::assertPushed(ProcessWarRoomAgent::class, 3);
    }

    public function test_dispatch_round_is_idempotent_per_round(): void
    {
        Queue::fake();

        $session = WarRoomSession::factory()->create([
            'selected_agents' => ['sre', 'dba'],
        ]);

        $this->service->dispatchRound($session, 1);
        $this->service->dispatchRound($session, 1); // second call is a no-op

        $messages = WarRoomMessage::where('session_id', $session->id)->where('round', 1)->get();
        $this->assertCount(2, $messages); // created once, not duplicated

        Queue::assertPushed(ProcessWarRoomAgent::class, 2);
    }

    // -----------------------------------------------------------------------
    // 4. onAgentCompleted - advances round when all done
    // -----------------------------------------------------------------------

    public function test_on_agent_completed_advances_round_when_all_done(): void
    {
        Queue::fake();
        Event::fake();

        $session = WarRoomSession::factory()->create([
            'status' => 'running',
            'current_round' => 0,
            'max_rounds' => 2,
            'selected_agents' => ['sre', 'dba'],
        ]);

        // Round 1: both agents completed
        $msg1 = WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'status' => 'completed',
        ]);
        $msg2 = WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'dba',
            'status' => 'completed',
        ]);

        $this->service->onAgentCompleted($session, $msg1);

        $session->refresh();
        $this->assertSame(1, $session->current_round);

        // Round 2 messages should have been dispatched
        $round2Messages = WarRoomMessage::where('session_id', $session->id)
            ->where('round', 2)
            ->get();
        $this->assertCount(2, $round2Messages);

        Queue::assertPushed(ProcessWarRoomAgent::class, 2);
    }

    public function test_on_agent_completed_does_nothing_when_pending_remain(): void
    {
        Queue::fake();
        Event::fake();

        $session = WarRoomSession::factory()->create([
            'status' => 'running',
            'current_round' => 0,
            'max_rounds' => 2,
            'selected_agents' => ['sre', 'dba'],
        ]);

        // Round 1: one completed, one still pending
        $completed = WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'status' => 'completed',
        ]);
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'dba',
            'status' => 'pending',
        ]);

        $this->service->onAgentCompleted($session, $completed);

        $session->refresh();
        // Round should NOT have advanced
        $this->assertSame(0, $session->current_round);

        // No new jobs dispatched
        Queue::assertNotPushed(ProcessWarRoomAgent::class);
        Queue::assertNotPushed(SynthesizeWarRoomReport::class);
    }

    // -----------------------------------------------------------------------
    // 5. onAgentCompleted - dispatches report on final round
    // -----------------------------------------------------------------------

    public function test_on_agent_completed_dispatches_report_on_final_round(): void
    {
        Queue::fake();
        Event::fake();

        $session = WarRoomSession::factory()->create([
            'status' => 'running',
            'current_round' => 1,
            'max_rounds' => 2,
            'selected_agents' => ['sre', 'dba'],
        ]);

        // Create round 1 messages (already completed from previous round)
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'status' => 'completed',
        ]);
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'dba',
            'status' => 'completed',
        ]);

        // Round 2 (final): both agents completed
        $msg1 = WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 2,
            'agent_role' => 'sre',
            'status' => 'completed',
        ]);
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 2,
            'agent_role' => 'dba',
            'status' => 'completed',
        ]);

        $this->service->onAgentCompleted($session, $msg1);

        // Should dispatch report synthesis, not another round
        Queue::assertPushed(SynthesizeWarRoomReport::class, 1);
        Queue::assertNotPushed(ProcessWarRoomAgent::class);
    }

    public function test_on_agent_completed_marks_session_failed_when_all_agents_fail(): void
    {
        Queue::fake();
        Event::fake();

        $session = WarRoomSession::factory()->create([
            'status' => 'running',
            'current_round' => 0,
            'max_rounds' => 2,
            'selected_agents' => ['sre'],
        ]);

        // All agents in round 1 failed
        $msg = WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'status' => 'failed',
        ]);

        $this->service->onAgentCompleted($session, $msg);

        $session->refresh();
        $this->assertSame('failed', $session->status);
        $this->assertStringContainsString('All agents failed', $session->error_message);

        Event::assertDispatched(\App\Events\WarRoomSessionCompleted::class);
    }

    // -----------------------------------------------------------------------
    // 6. markStuckMessages - re-dispatches stuck pending
    // -----------------------------------------------------------------------

    public function test_mark_stuck_messages_redispatches_stuck_pending(): void
    {
        Queue::fake();
        Event::fake();

        $session = WarRoomSession::factory()->running()->create([
            'selected_agents' => ['sre', 'dba'],
        ]);

        // A pending message older than 120 seconds (the pending timeout)
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'status' => 'pending',
            'created_at' => now()->subMinutes(3),
        ]);

        $count = $this->service->markStuckMessages($session);

        $this->assertSame(1, $count);
        Queue::assertPushed(ProcessWarRoomAgent::class, 1);
        Queue::assertPushed(ProcessWarRoomAgent::class, function ($job) use ($session) {
            return $job->session->id === $session->id;
        });
    }

    // -----------------------------------------------------------------------
    // 7. markStuckMessages - marks timed-out running as failed
    // -----------------------------------------------------------------------

    public function test_mark_stuck_messages_marks_timed_out_running(): void
    {
        Queue::fake();
        Event::fake();

        $timeout = config('ai.war_room.agent_timeout', 600);

        $session = WarRoomSession::factory()->running()->create([
            'selected_agents' => ['sre'],
        ]);

        // A running message older than the agent timeout
        $stuckRunning = WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'status' => 'running',
            'created_at' => now()->subSeconds($timeout + 60),
        ]);

        $count = $this->service->markStuckMessages($session);

        $this->assertSame(1, $count);
        $stuckRunning->refresh();
        $this->assertSame('failed', $stuckRunning->status);
        $this->assertStringContainsString('timed out', $stuckRunning->error_message);
    }

    public function test_mark_stuck_messages_returns_zero_when_session_not_running(): void
    {
        $session = WarRoomSession::factory()->completed()->create();

        $count = $this->service->markStuckMessages($session);

        $this->assertSame(0, $count);
    }

    public function test_mark_stuck_messages_ignores_recent_pending(): void
    {
        Queue::fake();

        $session = WarRoomSession::factory()->running()->create();

        // A recent pending message (under 120s threshold)
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'status' => 'pending',
            'created_at' => now()->subSeconds(30),
        ]);

        $count = $this->service->markStuckMessages($session);

        $this->assertSame(0, $count);
        Queue::assertNotPushed(ProcessWarRoomAgent::class);
    }

    // -----------------------------------------------------------------------
    // 7b. markStuckMessages - recovers a hung pre-analysis phase
    // -----------------------------------------------------------------------

    public function test_mark_stuck_messages_recovers_hung_pre_analysis(): void
    {
        Queue::fake();
        Event::fake();

        $stuckAfter = (int) config('ai.war_room.pre_analysis_timeout', 90)
            + \App\Jobs\WarRoom\RunPreAnalysis::JOB_TIMEOUT + 60;

        $session = WarRoomSession::factory()->running()->create([
            'selected_agents' => ['sre', 'dba'],
            'pre_analysis' => null,
            'started_at' => now()->subSeconds($stuckAfter + 60),
        ]);
        // No messages — pre-analysis never completed.

        $count = $this->service->markStuckMessages($session);

        $this->assertSame(1, $count);
        $round1 = WarRoomMessage::where('session_id', $session->id)->where('round', 1)->get();
        $this->assertCount(2, $round1); // forwarded to round 1
        Queue::assertPushed(ProcessWarRoomAgent::class, 2);
    }

    public function test_mark_stuck_messages_does_not_forward_recent_pre_analysis(): void
    {
        Queue::fake();

        $session = WarRoomSession::factory()->running()->create([
            'selected_agents' => ['sre', 'dba'],
            'pre_analysis' => null,
            'started_at' => now()->subSeconds(30), // freshly started, pre-analysis still in flight
        ]);

        $count = $this->service->markStuckMessages($session);

        $this->assertSame(0, $count);
        Queue::assertNotPushed(ProcessWarRoomAgent::class);
    }

    // -----------------------------------------------------------------------
    // 8. reanalyzeSession
    // -----------------------------------------------------------------------

    public function test_reanalyze_session_resets_session(): void
    {
        Queue::fake();

        $incident = Incident::factory()->create();

        $session = WarRoomSession::factory()->failed()->create([
            'model' => 'SMART-MODEL',
            'max_rounds' => 2,
            'deep_analysis' => true,
            'selected_agents' => ['sre', 'dba'],
            'incident_context' => ['old context'],
            'user_instructions' => 'original instructions',
            'tokens_used' => 5000,
        ]);
        $session->incidents()->sync([$incident->id]);

        // Create old messages from the failed run
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'status' => 'completed',
            'content' => 'old content',
        ]);
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'dba',
            'status' => 'running',
        ]);

        $this->markdownExporter
            ->shouldReceive('generateCompact')
            ->once()
            ->andReturn('New incident markdown context');

        $result = $this->service->reanalyzeSession(
            session: $session,
            userInstructions: 'New instructions',
        );

        $result->refresh();

        $this->assertSame('pending', $result->status);
        $this->assertSame(0, $result->current_round);
        $this->assertNull($result->final_report);
        $this->assertNull($result->final_report_html);
        $this->assertNull($result->started_at);
        $this->assertNull($result->completed_at);
        $this->assertNull($result->failed_at);
        $this->assertNull($result->error_message);
        $this->assertSame(0, $result->tokens_used);
        $this->assertSame('New instructions', $result->user_instructions);
        $this->assertFalse($result->context_summarized);

        // Old messages should be deleted
        $this->assertSame(0, WarRoomMessage::where('session_id', $session->id)->count());

        Queue::assertPushed(StartWarRoomSession::class, 1);
    }

    public function test_reanalyze_session_throws_for_active_session(): void
    {
        $session = WarRoomSession::factory()->running()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only completed or failed sessions can be re-analyzed');

        $this->service->reanalyzeSession($session);
    }

    // -----------------------------------------------------------------------
    // 9. retryFailedAgent
    // -----------------------------------------------------------------------

    public function test_retry_failed_agent_resets_message(): void
    {
        Queue::fake();

        $session = WarRoomSession::factory()->running()->create();
        $message = WarRoomMessage::factory()->failed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'retry_count' => 0,
            'error_message' => 'Something broke',
        ]);

        $this->service->retryFailedAgent($message);

        $message->refresh();
        $this->assertSame('pending', $message->status);
        $this->assertNull($message->error_message);
        $this->assertSame(1, $message->retry_count);

        Queue::assertPushed(ProcessWarRoomAgent::class, 1);
        Queue::assertPushed(ProcessWarRoomAgent::class, function ($job) use ($session) {
            return $job->session->id === $session->id
                && $job->agentRole === 'sre'
                && $job->round === 1;
        });
    }

    public function test_retry_failed_agent_restores_failed_session_to_running(): void
    {
        Queue::fake();

        $session = WarRoomSession::factory()->failed()->create();
        $message = WarRoomMessage::factory()->failed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
        ]);

        $this->service->retryFailedAgent($message);

        $session->refresh();
        $this->assertSame('running', $session->status);
        $this->assertNull($session->error_message);
    }

    // -----------------------------------------------------------------------
    // 10. getSessionData
    // -----------------------------------------------------------------------

    public function test_get_session_data_returns_expected_structure(): void
    {
        $user = User::factory()->create(['name' => 'Test User']);
        $incident = Incident::factory()->create([
            'no' => '2026_IN_P1_001',
            'title' => 'Test Incident',
            'severity' => 'p1',
            'incident_status' => 'Open',
        ]);

        $session = WarRoomSession::factory()->create([
            'user_id' => $user->id,
            'incident_id' => $incident->id,
            'status' => 'running',
            'current_round' => 1,
            'max_rounds' => 2,
            'model' => 'SMART-MODEL',
            'moderator_model' => 'SMART-MODEL',
            'enable_web_search' => false,
            'deep_analysis' => true,
            'selected_agents' => ['sre', 'dba'],
            'tokens_used' => 1500,
        ]);
        $session->incidents()->sync([$incident->id]);

        // Create agent config so getSessionData can resolve agent_name/icon/color
        WarRoomAgentConfig::create([
            'role_key' => 'sre',
            'display_name' => 'SRE Analyst',
            'icon' => 'heroicon-o-server',
            'color' => 'blue',
            'description' => 'Site Reliability Engineer',
            'skills' => ['Analysis'],
            'system_prompt' => 'You are an SRE agent.',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'content' => 'SRE analysis results',
            'response_time_ms' => 3000,
            'total_tokens' => 800,
        ]);
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'dba',
            'status' => 'pending',
        ]);

        // Clear the agent config cache so it picks up the new config
        cache()->forget('war_room:agent_configs:keyed');

        $data = $this->service->getSessionData($session);

        // Top-level keys
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('incident_id', $data);
        $this->assertArrayHasKey('incident', $data);
        $this->assertArrayHasKey('incidents', $data);
        $this->assertArrayHasKey('user_name', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('current_round', $data);
        $this->assertArrayHasKey('max_rounds', $data);
        $this->assertArrayHasKey('model', $data);
        $this->assertArrayHasKey('moderator_model', $data);
        $this->assertArrayHasKey('enable_web_search', $data);
        $this->assertArrayHasKey('deep_analysis', $data);
        $this->assertArrayHasKey('selected_agents', $data);
        $this->assertArrayHasKey('tokens_used', $data);
        $this->assertArrayHasKey('messages', $data);
        $this->assertArrayHasKey('final_report', $data);
        $this->assertArrayHasKey('final_report_html', $data);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('completed_at', $data);
        $this->assertArrayHasKey('failed_at', $data);
        $this->assertArrayHasKey('error_message', $data);
        $this->assertArrayHasKey('context_summarized', $data);
        $this->assertArrayHasKey('user_instructions', $data);

        // Value assertions
        $this->assertSame('running', $data['status']);
        $this->assertSame(1, $data['current_round']);
        $this->assertSame(2, $data['max_rounds']);
        $this->assertSame('SMART-MODEL', $data['model']);
        $this->assertSame('Test User', $data['user_name']);
        $this->assertEquals(['sre', 'dba'], $data['selected_agents']);
        $this->assertSame(1500, $data['tokens_used']);

        // Incident data
        $this->assertNotNull($data['incident']);
        $this->assertSame('2026_IN_P1_001', $data['incident']['no']);
        $this->assertSame('Test Incident', $data['incident']['title']);

        // Messages are grouped by round
        $this->assertArrayHasKey(1, $data['messages']);
        $roundMessages = $data['messages'][1];
        $this->assertCount(2, $roundMessages);

        // Completed message structure
        $sreMsg = collect($roundMessages)->firstWhere('agent_role', 'sre');
        $this->assertArrayHasKey('id', $sreMsg);
        $this->assertArrayHasKey('round', $sreMsg);
        $this->assertArrayHasKey('agent_role', $sreMsg);
        $this->assertArrayHasKey('agent_name', $sreMsg);
        $this->assertArrayHasKey('agent_icon', $sreMsg);
        $this->assertArrayHasKey('agent_color', $sreMsg);
        $this->assertArrayHasKey('content', $sreMsg);
        $this->assertArrayHasKey('status', $sreMsg);
        $this->assertArrayHasKey('response_time_ms', $sreMsg);
        $this->assertArrayHasKey('total_tokens', $sreMsg);
        $this->assertArrayHasKey('error_message', $sreMsg);
        $this->assertArrayHasKey('web_search_context', $sreMsg);
        $this->assertArrayHasKey('created_at', $sreMsg);

        $this->assertSame('completed', $sreMsg['status']);
        $this->assertSame('SRE analysis results', $sreMsg['content']);
        $this->assertSame('SRE Analyst', $sreMsg['agent_name']);
        $this->assertSame('heroicon-o-server', $sreMsg['agent_icon']);
        $this->assertSame('blue', $sreMsg['agent_color']);
    }
}
