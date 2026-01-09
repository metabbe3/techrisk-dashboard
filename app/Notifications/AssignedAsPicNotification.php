<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignedAsPicNotification extends Notification
{
    use Queueable;

    public $incident;

    public function __construct(Incident $incident)
    {
        $this->incident = $incident;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'incident_id' => $this->incident->id,
            'title' => 'You have been assigned as PIC for an incident',
            'message' => "You have been assigned as the Person In Charge for the incident: {$this->incident->title}",
            'url' => route('filament.admin.resources.incidents.view', $this->incident),
        ];
    }
}
