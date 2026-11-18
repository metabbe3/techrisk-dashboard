<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Reminds the PIC that an incident's status information is stale — either
 * no status update at all (incident older than the stale threshold) or a
 * last update that is unsettled and older than it. Sent by the
 * reminders:send-incidents status-update lane; the rule lives on
 * Incident::statusUpdateReminderFlag() (owner 2026-11-10: the email waits
 * 6 days in the empty case, unlike the immediate list flag). Mail is NOT a
 * per-user channel here — the command sends ONE combined email for all
 * PICs via Support\ReminderMail (owner 2026-10-09).
 */
class StatusUpdateStaleReminder extends IncidentNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function broadcastType(): string
    {
        return 'incident.status_update_reminder';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $last = $this->incident->latestUpdateRecord();

        if ($last === null) {
            $days = (int) round($this->incident->created_at?->diffInDays(now()) ?? 0);

            return $this->templatedMessage(
                subject: "Incident [{$this->incident->no}] has no status updates ({$days}d)",
                headline: 'Status update needed',
                intro: "This incident has been in the system for {$days} days without a single status update. Please add the latest status so everyone stays current.",
                details: $this->details($last),
                notifiable: $notifiable,
            );
        }

        $days = (int) round(($last->update_date ?? $this->incident->created_at)?->diffInDays(now()) ?? 0);

        return $this->templatedMessage(
            subject: "Incident [{$this->incident->no}] status needs follow-up ({$days}d since last update)",
            headline: 'Status update needed',
            intro: "The last status update was {$days} days ago and is not Resolved/Recovered/Closed. Please follow up and record the latest status.",
            details: $this->details($last),
            notifiable: $notifiable,
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $last = $this->incident->latestUpdateRecord();

        return $this->baseDatabasePayload([
            'title' => 'Status update needed',
            'body' => $last === null
                ? "{$this->incident->title} — no status update yet"
                : "{$this->incident->title} — last status update {$last->update_date?->format('d M Y')}",
            'icon' => 'heroicon-o-clock',
            'icon_color' => 'warning',
            'type' => 'status_update_reminder',
        ]);
    }

    private function details(?object $last): array
    {
        return [
            'Incident' => "[{$this->incident->no}] {$this->incident->title}",
            'Severity' => $this->incident->severity?->value ?? '-',
            'Status' => $this->incident->incident_status?->value ?? '-',
            'PIC' => $this->incident->pic_names !== '' ? $this->incident->pic_names : 'Unassigned',
            'Last status update' => $last?->update_date?->format('d M Y H:i') ?? 'None yet',
        ];
    }
}
