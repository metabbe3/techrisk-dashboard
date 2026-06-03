<?php

namespace Tests\Unit\Jobs\WarRoom;

use App\Jobs\WarRoom\StartWarRoomSession;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartWarRoomSessionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_calls_start_session(): void
    {
        $session = WarRoomSession::factory()->create();
        $warRoomService = $this->createMock(WarRoomService::class);
        $warRoomService->expects($this->once())
            ->method('startSession')
            ->with($this->callback(function (WarRoomSession $s) use ($session) {
                return $s->id === $session->id;
            }));

        $this->app->instance(WarRoomService::class, $warRoomService);

        $job = new StartWarRoomSession($session);
        $job->handle($warRoomService);
    }

    public function test_failed_marks_session_failed(): void
    {
        $session = WarRoomSession::factory()->create(['status' => 'pending']);
        $exception = new \RuntimeException('Something went wrong');

        $job = new StartWarRoomSession($session);
        $job->failed($exception);

        $session->refresh();
        $this->assertEquals('failed', $session->status);
        $this->assertStringContainsString('Something went wrong', $session->error_message);
    }
}
