<?php

namespace App\Notifications;

use App\Models\Incident;

class IncidentStatusChanged extends IncidentNotification
{
    public function __construct(
        Incident $incident,
        public readonly string $oldStatus,
        public readonly string $newStatus
    ) {
        parent::__construct($incident);
    }

    public function broadcastType(): string
    {
        return 'incident.status.changed';
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return $this->buildIncidentMailMessage(
            'Incident Status Changed: '.$this->incident->title,
            [
                'The status of an incident has been updated:',
                '**Incident:** '.$this->incident->title,
                '**Old Status:** '.$this->oldStatus,
                '**New Status:** '.$this->newStatus,
            ],
            $notifiable,
            'Please review the updated status.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'Incident Status Changed',
            'body' => "Status changed from \"{$this->oldStatus}\" to \"{$this->newStatus}\" for: {$this->incident->title}",
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'icon' => 'heroicon-o-arrow-path',
            'icon_color' => 'info',
            'type' => 'incident_status_changed',
        ]);
    }
}
