<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Notifications\Messages\MailMessage;

class IncidentUpdated extends IncidentNotification
{
    public function __construct(
        Incident $incident,
        public readonly array $changes = []
    ) {
        parent::__construct($incident);
    }

    public function broadcastType(): string
    {
        return 'incident.updated';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = [
            'The incident you are assigned as PIC has been updated.',
            '**Incident:** '.$this->incident->title,
            '**Status:** '.$this->incident->incident_status->value,
            '**Severity:** '.$this->incident->severity->value,
        ];

        if (! empty($this->changes)) {
            $lines[] = '**Changes made:**';
            foreach ($this->changes as $field => $change) {
                $lines[] = "- {$field}: ".(! empty($change['from']) ? "'{$change['from']}' -> " : '')."'{$change['to']}'";
            }
        }

        return $this->buildIncidentMailMessage(
            'Incident Updated: '.$this->incident->title,
            $lines,
            $notifiable,
            'Please review the changes.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $changeCount = count($this->changes);
        $bodyText = $changeCount > 0
            ? "The incident \"{$this->incident->title}\" has {$changeCount} update".($changeCount > 1 ? 's' : '')
            : "The incident \"{$this->incident->title}\" has been updated.";

        return $this->baseDatabasePayload([
            'title' => 'Incident Updated',
            'body' => $bodyText,
            'changes' => $this->changes,
            'severity' => $this->incident->severity,
            'icon' => 'heroicon-o-pencil',
            'type' => 'incident_update',
        ]);
    }
}
