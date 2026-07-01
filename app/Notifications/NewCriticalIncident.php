<?php

namespace App\Notifications;

use App\Enums\Severity;
use Illuminate\Notifications\Messages\MailMessage;

class NewCriticalIncident extends IncidentNotification
{
    public function broadcastType(): string
    {
        return 'incident.critical';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = [
            'A new critical incident has been reported:',
            '**Title:** '.$this->incident->title,
            '**Severity:** '.$this->incident->severity->value,
            '**Type:** '.$this->incident->incident_type,
            '**Date:** '.$this->incident->incident_date->format('Y-m-d H:i'),
            '**Summary:** '.$this->incident->summary,
        ];

        if ($this->incident->pic) {
            $lines[] = '**PIC:** '.$this->incident->pic?->name ?? 'Unassigned';
        }

        return $this->buildIncidentMailMessage(
            '[CRITICAL] New '.$this->incident->severity->value.' Incident: '.$this->incident->title,
            $lines,
            $notifiable,
            'Please review and take appropriate action.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'New '.$this->incident->severity->value.' Incident',
            'body' => $this->incident->title,
            'severity' => $this->incident->severity,
            'icon' => 'heroicon-o-exclamation-triangle',
            'icon_color' => $this->incident->severity === Severity::P1 ? 'danger' : 'warning',
            'type' => 'critical_incident',
        ]);
    }
}
