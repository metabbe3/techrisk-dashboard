<?php

namespace Tests\Unit\Jobs\WarRoom;

use App\Jobs\WarRoom\SynthesizeWarRoomReport;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SynthesizeWarRoomReportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_calls_synthesize_report(): void
    {
        $session = WarRoomSession::factory()->create();
        $warRoomService = $this->createMock(WarRoomService::class);
        $warRoomService->expects($this->once())
            ->method('synthesizeReport')
            ->with($this->callback(function (WarRoomSession $s) use ($session) {
                return $s->id === $session->id;
            }));

        $this->app->instance(WarRoomService::class, $warRoomService);

        $job = new SynthesizeWarRoomReport($session);
        $job->handle($warRoomService);
    }

    public function test_failed_marks_session_failed(): void
    {
        $session = WarRoomSession::factory()->create(['status' => 'running']);
        $exception = new \RuntimeException('Synthesis failed unexpectedly');

        $job = new SynthesizeWarRoomReport($session);
        $job->failed($exception);

        $session->refresh();
        $this->assertEquals('failed', $session->status);
        $this->assertStringContainsString('Synthesis failed unexpectedly', $session->error_message);
    }
}
