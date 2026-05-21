<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\ActionImprovement;
use Illuminate\Notifications\Messages\MailMessage;

abstract class ActionImprovementNotification extends BaseNotification
{
    public function __construct(
        public readonly ActionImprovement $actionImprovement
    ) {}

    protected function incidentUrl(): string
    {
        return IncidentResource::getUrl('view', ['record' => $this->actionImprovement->incident]);
    }

    protected function dueDateString(): ?string
    {
        return $this->actionImprovement->due_date?->format('Y-m-d');
    }

    protected function baseDatabasePayload(array $overrides = []): array
    {
        return $this->filamentDatabaseFormat(array_merge([
            'action_improvement_id' => $this->actionImprovement->id,
            'incident_id' => $this->actionImprovement->incident_id,
            'due_date' => $this->dueDateString(),
            'url' => $this->incidentUrl(),
        ], $overrides));
    }

    protected function buildActionMailMessage(string $subject, array $extraLines = [], ?object $notifiable = null, string $closingLine = 'Please complete this action improvement before the due date.'): MailMessage
    {
        $incident = $this->actionImprovement->incident;

        $lines = array_merge([
            '**Incident:** '.$incident->title,
            '**Action:** '.$this->actionImprovement->title,
            '**Detail:** '.$this->actionImprovement->detail,
            '**Due Date:** '.$this->dueDateString() ?? 'No due date',
        ], $extraLines);

        return $this->buildMailMessage($subject, $lines, $this->incidentUrl(), 'View Incident', $notifiable)
            ->line($closingLine);
    }
}
