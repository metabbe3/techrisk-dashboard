<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\User;

class PicAssignedNotification extends IncidentNotification
{
    public function __construct(
        Incident $incident,
        public readonly User $assignedPic
    ) {
        parent::__construct($incident);
    }

    public function broadcastType(): string
    {
        return 'incident.pic-assigned';
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return $this->buildIncidentMailMessage(
            'Team Member Assigned: '.$this->assignedPic->name,
            [
                'A team member has been assigned as PIC for an incident:',
                '**PIC:** '.$this->assignedPic->name,
                '**Incident:** '.$this->incident->title,
                '**Severity:** '.$this->incident->severity,
                '**Status:** '.$this->incident->incident_status,
            ],
            $notifiable,
            'You are receiving this as a team lead.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'pic_id' => $this->assignedPic->id,
            'title' => 'Team Member Assigned',
            'body' => $this->assignedPic->name.' was assigned as PIC for: '.$this->incident->title,
            'icon' => 'heroicon-o-user-plus',
            'icon_color' => 'info',
            'type' => 'pic_assigned',
        ]);
    }
}
