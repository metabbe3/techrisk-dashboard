<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminAnnouncement extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function broadcastType(): string
    {
        return 'admin.announcement';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[Announcement] '.$this->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->body);

        if ($this->url) {
            $mail->action('Learn More', $this->url);
        }

        return $mail->line('— Technical Risk Dashboard');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => 'heroicon-o-megaphone',
            'icon_color' => 'primary',
            'type' => 'admin_announcement',
            'format' => 'filament',
        ];
    }
}
