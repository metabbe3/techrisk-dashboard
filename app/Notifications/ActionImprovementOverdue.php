<?php

namespace App\Notifications;

use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionImprovementOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public $actionImprovement;
    public $daysOverdue;

    public function __construct(ActionImprovement $actionImprovement, int $daysOverdue)
    {
        $this->actionImprovement = $actionImprovement;
        $this->daysOverdue = $daysOverdue;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $incident = $this->actionImprovement->incident;

        return (new MailMessage)
            ->subject('[URGENT] Action Improvement OVERDUE')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('⚠️ An action improvement assigned to you is OVERDUE:')
            ->line('**Incident:** ' . $incident->title)
            ->line('**Action:** ' . $this->actionImprovement->title)
            ->line('**Due Date:** ' . $this->actionImprovement->due_date->format('Y-m-d'))
            ->line('**Days Overdue:** ' . $this->daysOverdue)
            ->line('**Status:** ' . ucfirst($this->actionImprovement->status))
            ->line('**Detail:** ' . $this->actionImprovement->detail)
            ->action('View Incident', url('/admin/incidents/' . $incident->id . '/edit'))
            ->line('Please complete this action improvement as soon as possible.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament', // Required for bell icon display
            'action_improvement_id' => $this->actionImprovement->id,
            'incident_id' => $this->actionImprovement->incident_id,
            'title' => 'Action Improvement OVERDUE',
            'message' => '"' . $this->actionImprovement->title . '" is ' . $this->daysOverdue . ' days overdue',
            'due_date' => $this->actionImprovement->due_date->format('Y-m-d'),
            'days_overdue' => $this->daysOverdue,
            'url' => url('/admin/incidents/' . $this->actionImprovement->incident_id . '/edit'),
            'icon' => 'heroicon-o-exclamation-circle',
            'icon_color' => 'danger',
            'type' => 'action_improvement_overdue',
        ];
    }
}
