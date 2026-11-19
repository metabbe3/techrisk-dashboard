<?php

declare(strict_types=1);

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
        return $this->brandedMessage(
            subject: $subject,
            headline: $subject,
            lines: [...$lines, $closingLine],
            actionText: 'View Incident',
            actionUrl: $this->incidentUrl(),
            notifiable: $notifiable,
        );
    }

    /**
     * Branded reminder email with a details table (the three reminder lanes).
     *
     * @param  array<string, string>  $details  label => value rows
     */
    protected function templatedMessage(string $subject, string $headline, string $intro, array $details, ?object $notifiable = null, string $actionText = 'View Incident'): MailMessage
    {
        return $this->brandedMessage(
            subject: $subject,
            headline: $headline,
            intro: $intro,
            details: $details,
            actionText: $actionText,
            actionUrl: $this->incidentUrl(),
            notifiable: $notifiable,
        );
    }
}
