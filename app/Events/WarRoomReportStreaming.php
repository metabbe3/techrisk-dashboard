<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarRoomReportStreaming implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
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
            'delta' => $this->delta,
            'content_length' => $this->contentLength,
        ];
    }

    public function broadcastAs(): string
    {
        return 'report.streaming';
    }
}
