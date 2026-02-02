<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IncidentUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Incident $incident,
        public readonly array $changes = []
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function broadcastType(): string
    {
        return 'incident.updated';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Incident Updated: '.$this->incident->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('The incident you are assigned as PIC has been updated.')
            ->line('**Incident:** '.$this->incident->title)
            ->line('**Status:** '.$this->incident->incident_status)
            ->line('**Severity:** '.$this->incident->severity);

        if (! empty($this->changes)) {
            $mail->line('**Changes made:**');
            foreach ($this->changes as $field => $change) {
                $mail->line("- {$field}: ".(! empty($change['from']) ? "'{$change['from']}' → " : '')."'{$change['to']}'");
            }
        }

        $mail->action('View Incident', IncidentResource::getUrl('view', ['record' => $this->incident]))
            ->line('Please review the changes.');

        return $mail;
    }

    /**
     * Filament V3 reads these specific keys from the data array:
     * - title: Displayed in bold in the notification list
     * - body: The description text (changed from 'message' in V2)
     * - url: The action URL when clicking the notification
     * - icon: (optional) Filament icon class
     */
    public function toDatabase(object $notifiable): array
    {
        $changeCount = count($this->changes);
        $bodyText = $changeCount > 0
            ? "The incident \"{$this->incident->title}\" has {$changeCount} update".($changeCount > 1 ? 's' : '')
            : "The incident \"{$this->incident->title}\" has been updated.";

        return [
            'incident_id' => $this->incident->id,
            'title' => 'Incident Updated',
            'body' => $bodyText,
            'changes' => $this->changes,
            'severity' => $this->incident->severity,
            'url' => IncidentResource::getUrl('view', ['record' => $this->incident]),
            'icon' => 'heroicon-o-pencil',
            'type' => 'incident_update',
            'format' => 'filament',
        ];
    }
}
