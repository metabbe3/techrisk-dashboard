<?php

namespace App\Events;

use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarRoomMessageUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WarRoomSession $session,
        public WarRoomMessage $message
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
            'message_id' => $this->message->id,
            'round' => $this->message->round,
            'agent_role' => $this->message->agent_role,
            'status' => $this->message->status,
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }
}
