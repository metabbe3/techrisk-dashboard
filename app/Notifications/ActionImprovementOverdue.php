<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionImprovementOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ActionImprovement $actionImprovement,
        public readonly int $daysOverdue
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function broadcastType(): string
    {
        return 'action.improvement.overdue';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $incident = $this->actionImprovement->incident;

        return (new MailMessage)
            ->subject('[URGENT] Action Improvement OVERDUE')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('⚠️ An action improvement assigned to you is OVERDUE:')
            ->line('**Incident:** '.$incident->title)
            ->line('**Action:** '.$this->actionImprovement->title)
            ->line('**Due Date:** '.$this->actionImprovement->due_date->format('Y-m-d'))
            ->line('**Days Overdue:** '.$this->daysOverdue)
            ->line('**Status:** '.ucfirst($this->actionImprovement->status))
            ->line('**Detail:** '.$this->actionImprovement->detail)
            ->action('View Incident', IncidentResource::getUrl('view', ['record' => $incident]))
            ->line('Please complete this action improvement as soon as possible.');
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
        return [
            'action_improvement_id' => $this->actionImprovement->id,
            'incident_id' => $this->actionImprovement->incident_id,
            'title' => 'Action Improvement OVERDUE',
            'body' => '"'.$this->actionImprovement->title.'" is '.$this->daysOverdue.' days overdue',
            'due_date' => $this->actionImprovement->due_date->format('Y-m-d'),
            'days_overdue' => $this->daysOverdue,
            'url' => IncidentResource::getUrl('view', ['record' => $this->actionImprovement->incident]),
            'icon' => 'heroicon-o-exclamation-circle',
            'icon_color' => 'danger',
            'type' => 'action_improvement_overdue',
            'format' => 'filament',
        ];
    }
}
