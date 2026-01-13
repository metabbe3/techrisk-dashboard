<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\StatusUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewStatusUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public $incident;
    public $statusUpdate;

    public function __construct(Incident $incident, StatusUpdate $statusUpdate)
    {
        $this->incident = $incident;
        $this->statusUpdate = $statusUpdate;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Status Update: ' . $this->incident->title)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A new status update has been added to an incident:')
            ->line('**Incident:** ' . $this->incident->title)
            ->line('**Status:** ' . $this->statusUpdate->status)
            ->line('**Notes:** ' . ($this->statusUpdate->notes ?: 'No notes provided'))
            ->line('**Updated:** ' . $this->statusUpdate->created_at->format('Y-m-d H:i'))
            ->action('View Incident', url('/admin/incidents/' . $this->incident->id . '/edit'))
            ->line('Please review the latest update.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament', // Required for bell icon display
            'incident_id' => $this->incident->id,
            'status_update_id' => $this->statusUpdate->id,
            'title' => 'New Status Update',
            'message' => "Status update: \"{$this->statusUpdate->status}\"",
            'status' => $this->statusUpdate->status,
            'notes' => $this->statusUpdate->notes,
            'url' => url('/admin/incidents/' . $this->incident->id . '/edit'),
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'icon_color' => 'success',
            'type' => 'new_status_update',
        ];
    }
}
