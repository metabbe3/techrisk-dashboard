<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IncidentUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public $incident;
    public $changes;

    public function __construct(Incident $incident, array $changes = [])
    {
        $this->incident = $incident;
        $this->changes = $changes;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Incident Updated: ' . $this->incident->title)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('The incident you are assigned as PIC has been updated.')
            ->line('**Incident:** ' . $this->incident->title)
            ->line('**Status:** ' . $this->incident->incident_status)
            ->line('**Severity:** ' . $this->incident->severity);

        if (!empty($this->changes)) {
            $mail->line('**Changes made:**');
            foreach ($this->changes as $field => $change) {
                $mail->line("- {$field}: " . (!empty($change['from']) ? "'{$change['from']}' → " : '') . "'{$change['to']}'");
            }
        }

        $mail->action('View Incident', url('/admin/incidents/' . $this->incident->id . '/edit'))
              ->line('Please review the changes.');

        return $mail;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament', // Required for bell icon display
            'incident_id' => $this->incident->id,
            'title' => 'Incident Updated',
            'message' => 'The incident "' . $this->incident->title . '" has been updated.',
            'changes' => $this->changes,
            'severity' => $this->incident->severity,
            'url' => url('/admin/incidents/' . $this->incident->id . '/edit'),
            'icon' => 'heroicon-o-pencil',
            'type' => 'incident_update',
        ];
    }
}
