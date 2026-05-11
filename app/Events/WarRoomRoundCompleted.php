<?php

namespace App\Events;

use App\Models\WarRoomSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarRoomRoundCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WarRoomSession $session,
        public int $round
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
            'round' => $this->round,
        ];
    }

    public function broadcastAs(): string
    {
        return 'round.completed';
    }
}
