<?php

namespace App\Notifications;

use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionImprovementReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public $actionImprovement;

    public function __construct(ActionImprovement $actionImprovement)
    {
        $this->actionImprovement = $actionImprovement;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $incident = $this->actionImprovement->incident;
        $daysUntilDue = now()->diffInDays($this->actionImprovement->due_date, false);
        $isOverdue = $daysUntilDue < 0;

        $mail = (new MailMessage)
            ->subject($isOverdue
                ? '[OVERDUE] Action Improvement Required'
                : '[Reminder] Action Improvement Due Soon'
            )
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($isOverdue
                ? 'This action improvement is OVERDUE:'
                : 'This is a reminder for an action improvement:'
            )
            ->line('**Incident:** ' . $incident->title)
            ->line('**Action:** ' . $this->actionImprovement->title)
            ->line('**Due Date:** ' . $this->actionImprovement->due_date->format('Y-m-d'))
            ->line('**Status:** ' . ucfirst($this->actionImprovement->status));

        if ($isOverdue) {
            $mail->line('⚠️ **' . abs($daysUntilDue) . ' days overdue**');
        } else {
            $mail->line('⏰ **' . $daysUntilDue . ' days remaining**');
        }

        $mail->line('**Detail:** ' . $this->actionImprovement->detail)
              ->action('View Incident', url('/admin/incidents/' . $incident->id . '/edit'))
              ->line('Please take action as soon as possible.');

        return $mail;
    }

    public function toDatabase(object $notifiable): array
    {
        $daysUntilDue = now()->diffInDays($this->actionImprovement->due_date, false);
        $isOverdue = $daysUntilDue < 0;

        return [
            'format' => 'filament', // Required for bell icon display
            'action_improvement_id' => $this->actionImprovement->id,
            'incident_id' => $this->actionImprovement->incident_id,
            'title' => $isOverdue ? 'Action Improvement Overdue' : 'Action Improvement Reminder',
            'message' => '"' . $this->actionImprovement->title . '" is ' .
                ($isOverdue
                    ? abs($daysUntilDue) . ' days overdue'
                    : 'due in ' . $daysUntilDue . ' days'
                ),
            'due_date' => $this->actionImprovement->due_date->format('Y-m-d'),
            'days_until_due' => $daysUntilDue,
            'is_overdue' => $isOverdue,
            'url' => url('/admin/incidents/' . $this->actionImprovement->incident_id . '/edit'),
            'icon' => $isOverdue ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-bell',
            'icon_color' => $isOverdue ? 'danger' : 'warning',
            'type' => $isOverdue ? 'action_improvement_overdue' : 'action_improvement_reminder',
        ];
    }
}
