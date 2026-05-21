<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\StatusUpdate;

class NewStatusUpdate extends IncidentNotification
{
    public function __construct(
        Incident $incident,
        public readonly StatusUpdate $statusUpdate
    ) {
        parent::__construct($incident);
    }

    public function broadcastType(): string
    {
        return 'status.update';
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return $this->buildIncidentMailMessage(
            'New Status Update: '.$this->incident->title,
            [
                'A new status update has been added to an incident:',
                '**Incident:** '.$this->incident->title,
                '**Status:** '.$this->statusUpdate->status,
                '**Notes:** '.($this->statusUpdate->notes ?: 'No notes provided'),
                '**Updated:** '.$this->statusUpdate->created_at->format('Y-m-d H:i'),
            ],
            $notifiable,
            'Please review the latest update.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'status_update_id' => $this->statusUpdate->id,
            'title' => 'New Status Update',
            'body' => "Status update for \"{$this->incident->title}\": {$this->statusUpdate->status}",
            'status' => $this->statusUpdate->status,
            'notes' => $this->statusUpdate->notes,
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'icon_color' => 'success',
            'type' => 'new_status_update',
        ]);
    }
}
