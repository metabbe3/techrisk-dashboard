<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionImprovementAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ActionImprovement $actionImprovement
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function broadcastType(): string
    {
        return 'action.improvement.assigned';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $incident = $this->actionImprovement->incident;

        return (new MailMessage)
            ->subject('Action Improvement Assigned: '.$this->actionImprovement->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('You have been assigned an action improvement:')
            ->line('**Incident:** '.$incident->title)
            ->line('**Action:** '.$this->actionImprovement->title)
            ->line('**Detail:** '.$this->actionImprovement->detail)
            ->line('**Due Date:** '.$this->actionImprovement->due_date?->format('Y-m-d') ?? 'No due date')
            ->action('View Incident', IncidentResource::getUrl('view', ['record' => $incident]))
            ->line('Please complete this action improvement before the due date.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'action_improvement_id' => $this->actionImprovement->id,
            'incident_id' => $this->actionImprovement->incident_id,
            'title' => 'Action Improvement Assigned',
            'body' => '"'.$this->actionImprovement->title.'" has been assigned to you',
            'due_date' => $this->actionImprovement->due_date?->format('Y-m-d'),
            'url' => IncidentResource::getUrl('view', ['record' => $this->actionImprovement->incident]),
            'icon' => 'heroicon-o-clipboard-document-check',
            'icon_color' => 'info',
            'type' => 'action_improvement_assigned',
            'format' => 'filament',
        ];
    }
}
