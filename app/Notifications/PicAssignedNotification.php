<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PicAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Incident $incident,
        public readonly User $assignedPic
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function broadcastType(): string
    {
        return 'incident.pic-assigned';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Team Member Assigned: '.$this->assignedPic->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A team member has been assigned as PIC for an incident:')
            ->line('**PIC:** '.$this->assignedPic->name)
            ->line('**Incident:** '.$this->incident->title)
            ->line('**Severity:** '.$this->incident->severity)
            ->line('**Status:** '.$this->incident->incident_status)
            ->action('View Incident', IncidentResource::getUrl('view', ['record' => $this->incident]))
            ->line('You are receiving this as a team lead.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'incident_id' => $this->incident->id,
            'pic_id' => $this->assignedPic->id,
            'title' => 'Team Member Assigned',
            'body' => $this->assignedPic->name.' was assigned as PIC for: '.$this->incident->title,
            'url' => IncidentResource::getUrl('view', ['record' => $this->incident]),
            'icon' => 'heroicon-o-user-plus',
            'icon_color' => 'info',
            'type' => 'pic_assigned',
            'format' => 'filament',
        ];
    }
}
