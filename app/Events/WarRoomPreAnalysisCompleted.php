<?php

namespace App\Events;

use App\Models\WarRoomSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarRoomPreAnalysisCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WarRoomSession $session,
        public array $preAnalysis
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('war-room.'.$this->session->id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'pre_analysis' => $this->preAnalysis,
        ];
    }

    public function broadcastAs(): string
    {
        return 'pre-analysis.completed';
    }
}
