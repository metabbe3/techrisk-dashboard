<?php

namespace App\Jobs\WarRoom;

use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWarRoomAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 600;

    public function __construct(
        public WarRoomSession $session,
        public string $agentRole,
        public int $round
    ) {
        $this->onQueue('war-room');
    }

    public function handle(WarRoomService $warRoomService): void
    {
        try {
            $warRoomService->processAgent($this->session, $this->agentRole, $this->round);
        } catch (\Throwable $e) {
            Log::error('War Room agent processing failed', [
                'session_id' => $this->session->id,
                'agent_role' => $this->agentRole,
                'round' => $this->round,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $message = WarRoomMessage::where('session_id', $this->session->id)
            ->where('agent_role', $this->agentRole)
            ->where('round', $this->round)
            ->first();

        if ($message && $message->status !== 'completed') {
            $message->markFailed('Job failed after retries: '.$exception->getMessage());
        }
    }
}
