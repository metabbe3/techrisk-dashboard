<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Notifications\Messages\MailMessage;

abstract class IncidentNotification extends BaseNotification
{
    public function __construct(
        public readonly Incident $incident
    ) {}

    protected function incidentUrl(): string
    {
        return IncidentResource::getUrl('view', ['record' => $this->incident]);
    }

    protected function baseDatabasePayload(array $overrides = []): array
    {
        return $this->filamentDatabaseFormat(array_merge([
            'incident_id' => $this->incident->id,
            'url' => $this->incidentUrl(),
        ], $overrides));
    }

    protected function buildIncidentMailMessage(string $subject, array $lines, ?object $notifiable = null, string $closingLine = 'Please review and take appropriate action.'): MailMessage
    {
        return $this->buildMailMessage($subject, $lines, $this->incidentUrl(), 'View Incident', $notifiable)
            ->line($closingLine);
    }

    /**
     * Build a branded (HTML) reminder email from the incident-reminder template.
     *
     * @param  array<string, string>  $details  label => value rows
     */
    protected function templatedMessage(string $subject, string $headline, string $intro, array $details, ?object $notifiable = null, string $actionText = 'View Incident'): MailMessage
    {
        return (new MailMessage)
            ->subject($subject)
            ->view('emails.incident-reminder', [
                'greeting' => 'Hello '.$notifiable?->name.',',
                'headline' => $headline,
                'intro' => $intro,
                'details' => $details,
                'actionText' => $actionText,
                'actionUrl' => $this->incidentUrl(),
            ]);
    }
}
