<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class AdminAnnouncement extends BaseNotification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null
    ) {}

    public function broadcastType(): string
    {
        return 'admin.announcement';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = [$this->body, '— Technical Risk Dashboard'];

        $actionUrl = $this->url ?? route('filament.admin.pages.dashboard');

        return $this->buildMailMessage(
            '[Announcement] '.$this->title,
            $lines,
            $actionUrl,
            $this->url ? 'Learn More' : 'Go to Dashboard',
            $notifiable
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->filamentDatabaseFormat([
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => 'heroicon-o-megaphone',
            'icon_color' => 'primary',
            'type' => 'admin_announcement',
        ]);
    }
}
