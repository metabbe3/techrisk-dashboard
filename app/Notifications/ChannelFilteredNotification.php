<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ChannelFilteredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Notification $notification,
        protected array $allowedChannels,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->allowedChannels;
    }

    public function databaseType(): string
    {
        return get_class($this->notification);
    }

    public function broadcastType(): string
    {
        if (method_exists($this->notification, 'broadcastType')) {
            return $this->notification->broadcastType();
        }

        return get_class($this->notification);
    }

    public function toMail(object $notifiable): mixed
    {
        return $this->notification->toMail($notifiable);
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->notification->toDatabase($notifiable);
    }

    public function toArray(object $notifiable): array
    {
        if (method_exists($this->notification, 'toArray')) {
            return $this->notification->toArray($notifiable);
        }

        return [];
    }

    public function toBroadcast(object $notifiable): \Illuminate\Notifications\Messages\BroadcastMessage
    {
        if (method_exists($this->notification, 'toBroadcast')) {
            return $this->notification->toBroadcast($notifiable);
        }

        if (method_exists($this->notification, 'toArray')) {
            return new \Illuminate\Notifications\Messages\BroadcastMessage($this->notification->toArray($notifiable));
        }

        return new \Illuminate\Notifications\Messages\BroadcastMessage($this->toDatabase($notifiable));
    }
}
