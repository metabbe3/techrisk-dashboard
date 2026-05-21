<?php

namespace App\Notifications;

class AssignedAsPicNotification extends IncidentNotification
{
    public function broadcastType(): string
    {
        return 'incident.assignment';
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return $this->buildIncidentMailMessage(
            'New Incident Assignment: '.$this->incident->title,
            [
                'You have been assigned as the Person In Charge (PIC) for a new incident.',
                '**Incident:** '.$this->incident->title,
                '**Severity:** '.$this->incident->severity,
                '**Status:** '.$this->incident->incident_status,
                '**Date:** '.$this->incident->incident_date->format('Y-m-d H:i'),
            ],
            $notifiable,
            'Please review and take appropriate action.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'New Incident Assignment',
            'body' => "You have been assigned as PIC for: {$this->incident->title}",
            'severity' => $this->incident->severity,
            'icon' => 'heroicon-o-shield-exclamation',
            'icon_color' => $this->incident->severity === 'P1' ? 'danger' : 'warning',
            'type' => 'incident_assignment',
        ]);
    }
}
