<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignedAsPicNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Incident $incident
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function broadcastType(): string
    {
        return 'incident.assignment';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Incident Assignment: '.$this->incident->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('You have been assigned as the Person In Charge (PIC) for a new incident.')
            ->line('**Incident:** '.$this->incident->title)
            ->line('**Severity:** '.$this->incident->severity)
            ->line('**Status:** '.$this->incident->incident_status)
            ->line('**Date:** '.$this->incident->incident_date->format('Y-m-d H:i'))
            ->action('View Incident', IncidentResource::getUrl('view', ['record' => $this->incident]))
            ->line('Please review and take appropriate action.');
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
            'title' => 'New Incident Assignment',
            'body' => "You have been assigned as PIC for: {$this->incident->title}",
            'severity' => $this->incident->severity,
            'url' => IncidentResource::getUrl('view', ['record' => $this->incident]),
            'icon' => 'heroicon-o-shield-exclamation',
            'icon_color' => $this->incident->severity === 'P1' ? 'danger' : 'warning',
            'type' => 'incident_assignment',
            'format' => 'filament',
        ];
    }
}
