<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\StatusUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewStatusUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Incident $incident,
        public readonly StatusUpdate $statusUpdate
    ) {}

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
            ->action('View Incident', IncidentResource::getUrl('view', ['record' => $this->incident]))
            ->line('Please review the latest update.');
    }

    /**
     * Filament V3 reads these specific keys from the data array:
     * - title: Displayed in bold in the notification list
     * - body: The description text (changed from 'message' in V2)
     * - url: The action URL when clicking the notification
     * - icon: (optional) Filament icon class
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'incident_id' => $this->incident->id,
            'status_update_id' => $this->statusUpdate->id,
            'title' => 'New Status Update',
            'body' => "Status update for \"{$this->incident->title}\": {$this->statusUpdate->status}",
            'status' => $this->statusUpdate->status,
            'notes' => $this->statusUpdate->notes,
            'url' => IncidentResource::getUrl('view', ['record' => $this->incident]),
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'icon_color' => 'success',
            'type' => 'new_status_update',
        ];
    }
}
