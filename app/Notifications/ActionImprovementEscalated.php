<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionImprovementEscalated extends Notification implements ShouldQueue
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
        return 'action.improvement.escalated';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $incident = $this->actionImprovement->incident;

        return (new MailMessage)
            ->subject('[ESCALATED] Action Improvement '.$this->daysOverdue.' Days Overdue')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('An action improvement has been overdue for '.$this->daysOverdue.' days and requires your attention:')
            ->line('**Incident:** '.$incident->title)
            ->line('**Action:** '.$this->actionImprovement->title)
            ->line('**Detail:** '.$this->actionImprovement->detail)
            ->line('**Due Date:** '.$this->actionImprovement->due_date->format('Y-m-d'))
            ->line('**Days Overdue:** '.$this->daysOverdue)
            ->action('View Incident', IncidentResource::getUrl('view', ['record' => $incident]))
            ->line('This is an automated escalation. Please follow up with the assigned PIC.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'action_improvement_id' => $this->actionImprovement->id,
            'incident_id' => $this->actionImprovement->incident_id,
            'title' => 'Overdue Escalation ('.$this->daysOverdue.'d)',
            'body' => '"'.$this->actionImprovement->title.'" has been overdue for '.$this->daysOverdue.' days',
            'due_date' => $this->actionImprovement->due_date->format('Y-m-d'),
            'days_overdue' => $this->daysOverdue,
            'url' => IncidentResource::getUrl('view', ['record' => $this->actionImprovement->incident]),
            'icon' => 'heroicon-o-exclamation-triangle',
            'icon_color' => 'danger',
            'type' => 'action_improvement_escalated',
            'format' => 'filament',
        ];
    }
}
