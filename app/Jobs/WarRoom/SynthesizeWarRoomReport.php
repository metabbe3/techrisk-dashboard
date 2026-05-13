<?php

namespace App\Jobs\WarRoom;

use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SynthesizeWarRoomReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout;

    public function __construct(
        public WarRoomSession $session
    ) {
        $this->onQueue('war-room');
        $this->timeout = (int) config('ai.war_room.moderator_timeout', 600);
    }

    public function handle(WarRoomService $warRoomService): void
    {
        try {
            $warRoomService->synthesizeReport($this->session);
        } catch (\Throwable $e) {
            Log::error('War Room report synthesis failed', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->session->markFailed('Report synthesis failed: '.$exception->getMessage());
    }
}
