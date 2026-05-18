<?php

namespace App\Jobs\WarRoom;

use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ProcessWarRoomAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout;

    public bool $autoRetry = true;

    public function __construct(
        public WarRoomSession $session,
        public string $agentRole,
        public int $round,
        bool $autoRetry = true,
    ) {
        $this->onQueue('war-room');
        $this->timeout = (int) config('ai.war_room.agent_timeout', 600);
        $this->autoRetry = $autoRetry;
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

        if (! $message || $message->status === 'completed') {
            return;
        }

        $maxAutoRetries = (int) config('ai.war_room.auto_retry', 1);
        $isRetryable = $exception instanceof ConnectionException
            || $exception instanceof ProcessTimedOutException
            || str_contains($exception->getMessage(), 'timed out')
            || str_contains($exception->getMessage(), 'timeout');

        if ($this->autoRetry && $isRetryable && $message->retry_count < $maxAutoRetries) {
            Log::info('War Room agent auto-retrying', [
                'session_id' => $this->session->id,
                'agent_role' => $this->agentRole,
                'round' => $this->round,
                'retry_count' => $message->retry_count + 1,
                'error' => $exception->getMessage(),
            ]);

            $message->update([
                'status' => 'pending',
                'error_message' => null,
                'retry_count' => $message->retry_count + 1,
            ]);

            self::dispatch($this->session, $this->agentRole, $this->round, false)
                ->delay(now()->addSeconds(10));

            return;
        }

        $message->markFailed('Job failed after retries: '.$exception->getMessage());
    }
}
