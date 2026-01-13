<?php

namespace App\Notifications;

use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionImprovementDueSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public $actionImprovement;
    public $daysRemaining;

    public function __construct(ActionImprovement $actionImprovement, int $daysRemaining)
    {
        $this->actionImprovement = $actionImprovement;
        $this->daysRemaining = $daysRemaining;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $incident = $this->actionImprovement->incident;

        return (new MailMessage)
            ->subject('[Reminder] Action Improvement Due in ' . $this->daysRemaining . ' Days')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('You have an action improvement that will be due soon:')
            ->line('**Incident:** ' . $incident->title)
            ->line('**Action:** ' . $this->actionImprovement->title)
            ->line('**Due Date:** ' . $this->actionImprovement->due_date->format('Y-m-d'))
            ->line('**Days Remaining:** ' . $this->daysRemaining)
            ->line('**Detail:** ' . $this->actionImprovement->detail)
            ->action('View Incident', url('/admin/incidents/' . $incident->id . '/edit'))
            ->line('Please complete this action improvement before the due date.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament', // Required for bell icon display
            'action_improvement_id' => $this->actionImprovement->id,
            'incident_id' => $this->actionImprovement->incident_id,
            'title' => 'Action Improvement Due Soon',
            'message' => '"' . $this->actionImprovement->title . '" is due in ' . $this->daysRemaining . ' days',
            'due_date' => $this->actionImprovement->due_date->format('Y-m-d'),
            'days_remaining' => $this->daysRemaining,
            'url' => url('/admin/incidents/' . $this->actionImprovement->incident_id . '/edit'),
            'icon' => 'heroicon-o-clock',
            'icon_color' => 'warning',
            'type' => 'action_improvement_due_soon',
        ];
    }
}
