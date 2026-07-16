<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Reminds the PIC that an incident is still open / not completed.
 * Sent by the reminders:send-incidents command for incidents whose
 * incident_status != Completed and that are older than the configured
 * age threshold.
 */
class IncidentNotDoneReminder extends IncidentNotification
{
    public function broadcastType(): string
    {
        return 'incident.not_done_reminder';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = (int) round($this->incident->incident_date?->diffInDays(now()) ?? 0);

        return $this->templatedMessage(
            subject: "Incident [{$this->incident->no}] is still open ({$days}d)",
            headline: 'Incident still open',
            intro: "This incident has been open for {$days} days and has not been completed. Please review and take action.",
            details: [
                'Incident' => "[{$this->incident->no}] {$this->incident->title}",
                'Severity' => $this->incident->severity?->value ?? '-',
                'Status' => $this->incident->incident_status?->value ?? '-',
                'PIC' => $this->incident->pic?->name ?? 'Unassigned',
            ],
            notifiable: $notifiable,
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $days = (int) round($this->incident->incident_date?->diffInDays(now()) ?? 0);

        return $this->baseDatabasePayload([
            'title' => 'Incident still open',
            'body' => "{$this->incident->title} — open for {$days} days",
            'icon' => 'heroicon-o-clock',
            'icon_color' => 'warning',
            'type' => 'incident_not_done_reminder',
        ]);
    }
}
