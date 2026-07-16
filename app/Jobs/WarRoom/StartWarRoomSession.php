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

class StartWarRoomSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 60;

    public function __construct(
        public WarRoomSession $session
    ) {
        $this->onQueue('war-room');
    }

    public function handle(WarRoomService $warRoomService): void
    {
        try {
            $warRoomService->startSession($this->session);
        } catch (\Throwable $e) {
            Log::error('Failed to start War Room session', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage(),
            ]);
            $this->session->markFailed('Failed to start: '.$e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->session->markFailed('Start job failed: '.$exception->getMessage());
    }
}
