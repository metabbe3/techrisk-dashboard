<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IncidentStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Incident $incident,
        public readonly string $oldStatus,
        public readonly string $newStatus
    ) {}

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
            ->action('View Incident', IncidentResource::getUrl('view', ['record' => $this->incident]))
            ->line('Please review the updated status.');
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
            'title' => 'Incident Status Changed',
            'body' => "Status changed from \"{$this->oldStatus}\" to \"{$this->newStatus}\" for: {$this->incident->title}",
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'url' => IncidentResource::getUrl('view', ['record' => $this->incident]),
            'icon' => 'heroicon-o-arrow-path',
            'icon_color' => 'info',
            'type' => 'incident_status_changed',
            'format' => 'filament',
        ];
    }
}
