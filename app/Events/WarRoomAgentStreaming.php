<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarRoomAgentStreaming implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $messageId,
        public string $agentRole,
        public string $delta,
        public int $contentLength,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('war-room.'.$this->sessionId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'message_id' => $this->messageId,
            'agent_role' => $this->agentRole,
            'delta' => $this->delta,
            'content_length' => $this->contentLength,
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent.streaming';
    }
}
