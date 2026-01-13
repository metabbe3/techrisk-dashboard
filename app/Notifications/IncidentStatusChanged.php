<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IncidentStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public $incident;
    public $oldStatus;
    public $newStatus;

    public function __construct(Incident $incident, string $oldStatus, string $newStatus)
    {
        $this->incident = $incident;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Incident Status Changed: ' . $this->incident->title)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('The status of an incident has been updated:')
            ->line('**Incident:** ' . $this->incident->title)
            ->line('**Old Status:** ' . $this->oldStatus)
            ->line('**New Status:** ' . $this->newStatus)
            ->action('View Incident', url('/admin/incidents/' . $this->incident->id . '/edit'))
            ->line('Please review the updated status.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament', // Required for bell icon display
            'incident_id' => $this->incident->id,
            'title' => 'Incident Status Changed',
            'message' => "Status changed from \"{$this->oldStatus}\" to \"{$this->newStatus}\"",
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'url' => url('/admin/incidents/' . $this->incident->id . '/edit'),
            'icon' => 'heroicon-o-arrow-path',
            'icon_color' => 'info',
            'type' => 'incident_status_changed',
        ];
    }
}
