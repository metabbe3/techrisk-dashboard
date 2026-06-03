<?php

namespace Tests\Unit\Jobs\WarRoom;

use App\Jobs\WarRoom\ProcessWarRoomAgent;
use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessWarRoomAgentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_calls_process_agent(): void
    {
        $session = WarRoomSession::factory()->create();
        $warRoomService = $this->createMock(WarRoomService::class);
        $warRoomService->expects($this->once())
            ->method('processAgent')
            ->with(
                $this->callback(function (WarRoomSession $s) use ($session) {
                    return $s->id === $session->id;
                }),
                $this->equalTo('sre'),
                $this->equalTo(1),
            );

        $this->app->instance(WarRoomService::class, $warRoomService);

        $job = new ProcessWarRoomAgent($session, 'sre', 1);
        $job->handle($warRoomService);
    }

    public function test_failed_marks_message_failed(): void
    {
        $session = WarRoomSession::factory()->create();
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'agent_role' => 'sre',
            'round' => 1,
            'status' => 'running',
            'retry_count' => 0,
        ]);

        $exception = new \RuntimeException('Agent processing error');

        Queue::fake();

        $job = new ProcessWarRoomAgent($session, 'sre', 1);
        $job->failed($exception);

        $message = WarRoomMessage::where('session_id', $session->id)
            ->where('agent_role', 'sre')
            ->where('round', 1)
            ->first();

        $this->assertEquals('failed', $message->status);
        $this->assertStringContainsString('Agent processing error', $message->error_message);
    }

    public function test_failed_auto_retries_on_connection_exception(): void
    {
        $session = WarRoomSession::factory()->create();
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'agent_role' => 'sre',
            'round' => 1,
            'status' => 'running',
            'retry_count' => 0,
        ]);

        $exception = new ConnectionException('Connection timed out');

        Queue::fake();

        $job = new ProcessWarRoomAgent($session, 'sre', 1, true);
        $job->failed($exception);

        $message = WarRoomMessage::where('session_id', $session->id)
            ->where('agent_role', 'sre')
            ->where('round', 1)
            ->first();

        $this->assertEquals('pending', $message->status);
        $this->assertEquals(1, $message->retry_count);
        $this->assertNull($message->error_message);

        Queue::assertPushed(ProcessWarRoomAgent::class, function ($dispatchedJob) use ($session) {
            return $session->id === $dispatchedJob->session->id
                && $dispatchedJob->agentRole === 'sre'
                && $dispatchedJob->round === 1
                && $dispatchedJob->autoRetry === false;
        });
    }

    public function test_failed_does_not_retry_beyond_max(): void
    {
        $session = WarRoomSession::factory()->create();
        $maxRetries = (int) config('ai.war_room.auto_retry', 1);

        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'agent_role' => 'sre',
            'round' => 1,
            'status' => 'running',
            'retry_count' => $maxRetries,
        ]);

        $exception = new ConnectionException('Connection timed out');

        Queue::fake();

        $job = new ProcessWarRoomAgent($session, 'sre', 1, true);
        $job->failed($exception);

        $message = WarRoomMessage::where('session_id', $session->id)
            ->where('agent_role', 'sre')
            ->where('round', 1)
            ->first();

        $this->assertEquals('failed', $message->status);
        $this->assertStringContainsString('Connection timed out', $message->error_message);

        Queue::assertNotPushed(ProcessWarRoomAgent::class);
    }
}
