<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Filament\Resources\ActionImprovementResource;
use App\Filament\Resources\IncidentResource;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Models\ActionImprovement;
use Illuminate\Notifications\Messages\MailMessage;

abstract class ActionImprovementNotification extends BaseNotification
{
    public function __construct(
        public readonly ActionImprovement $actionImprovement
    ) {}

    /**
     * The incident's Action Improvements tab — the primary button lands the
     * reader directly on the relation manager (not the incident header, not
     * the edit page PICs can't open). Index computed so reordering
     * IncidentResource::getRelations() can't break the query string.
     */
    protected function actionItemsUrl(): string
    {
        $tab = array_search(ActionImprovementsRelationManager::class, IncidentResource::getRelations(), true);

        return IncidentResource::getUrl('view', ['record' => $this->actionImprovement->incident])
            .'?activeRelationManager='.($tab !== false ? $tab : 0);
    }

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
            'url' => $this->actionItemsUrl(),
        ], $overrides));
    }

    /**
     * Branded action-improvement email: subject/headline + details table +
     * "Open action items" deep link. Every due-soon/overdue/escalation/
     * assigned/reminder mail renders through here.
     *
     * @param  array<int, string>  $extraLines  lead paragraphs under the intro
     * @param  array<string, string>  $extraDetails  rows appended to the table
     */
    protected function buildActionMailMessage(
        string $subject,
        string $headline,
        string $intro,
        array $extraLines = [],
        array $extraDetails = [],
        ?object $notifiable = null,
        string $closingLine = 'Please complete this action improvement before the due date.',
    ): MailMessage {
        $action = $this->actionImprovement;
        $incident = $action->incident;
        $statusMap = ActionImprovementFormSchema::statusOptions();

        $details = array_filter([
            'Incident' => "[{$incident->no}] {$incident->title}",
            'Action' => $action->title,
            'Detail' => $action->detail !== null ? \Illuminate\Support\Str::limit($action->detail, 500) : null,
            'Status' => $statusMap[$action->status] ?? ucfirst($action->status),
            'PIC' => implode(', ', $action->pic_email ?? []) ?: null,
            'Due date' => $action->due_date?->format('d M Y') ?? 'TBD — not set',
        ]) + $extraDetails;

        return $this->brandedMessage(
            subject: $subject,
            headline: $headline,
            intro: $intro,
            lines: [...$extraLines, $closingLine],
            details: $details,
            actionText: 'Open action items',
            actionUrl: $this->actionItemsUrl(),
            notifiable: $notifiable,
            secondaryText: 'All action items',
            secondaryUrl: ActionImprovementResource::getUrl('index'),
        );
    }
}
