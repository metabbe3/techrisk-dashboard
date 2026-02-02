<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionImprovementDueSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ActionImprovement $actionImprovement,
        public readonly int $daysRemaining
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function broadcastType(): string
    {
        return 'action.improvement.due.soon';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $incident = $this->actionImprovement->incident;

        return (new MailMessage)
            ->subject('[Reminder] Action Improvement Due in '.$this->daysRemaining.' Days')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('You have an action improvement that will be due soon:')
            ->line('**Incident:** '.$incident->title)
            ->line('**Action:** '.$this->actionImprovement->title)
            ->line('**Due Date:** '.$this->actionImprovement->due_date->format('Y-m-d'))
            ->line('**Days Remaining:** '.$this->daysRemaining)
            ->line('**Detail:** '.$this->actionImprovement->detail)
            ->action('View Incident', IncidentResource::getUrl('view', ['record' => $incident]))
            ->line('Please complete this action improvement before the due date.');
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
            'title' => 'Action Improvement Due Soon',
            'body' => '"'.$this->actionImprovement->title.'" is due in '.$this->daysRemaining.' days',
            'due_date' => $this->actionImprovement->due_date->format('Y-m-d'),
            'days_remaining' => $this->daysRemaining,
            'url' => IncidentResource::getUrl('view', ['record' => $this->actionImprovement->incident]),
            'icon' => 'heroicon-o-clock',
            'icon_color' => 'warning',
            'type' => 'action_improvement_due_soon',
            'format' => 'filament',
        ];
    }
}
