<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewCriticalIncident extends Notification implements ShouldQueue
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
        return 'incident.critical';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[CRITICAL] New '.$this->incident->severity.' Incident: '.$this->incident->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A new critical incident has been reported:')
            ->line('**Title:** '.$this->incident->title)
            ->line('**Severity:** '.$this->incident->severity)
            ->line('**Type:** '.$this->incident->incident_type)
            ->line('**Date:** '.$this->incident->incident_date->format('Y-m-d H:i'))
            ->line('**Summary:** '.$this->incident->summary)
            ->when($this->incident->pic, fn (MailMessage $m) => $m->line('**PIC:** '.$this->incident->pic?->name ?? 'Unassigned'))
            ->action('View Incident', IncidentResource::getUrl('view', ['record' => $this->incident]))
            ->line('Please review and take appropriate action.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'incident_id' => $this->incident->id,
            'title' => 'New '.$this->incident->severity.' Incident',
            'body' => $this->incident->title,
            'severity' => $this->incident->severity,
            'url' => IncidentResource::getUrl('view', ['record' => $this->incident]),
            'icon' => 'heroicon-o-exclamation-triangle',
            'icon_color' => $this->incident->severity === 'P1' ? 'danger' : 'warning',
            'type' => 'critical_incident',
            'format' => 'filament',
        ];
    }
}
