<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionImprovementReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ActionImprovement $actionImprovement
    ) {}

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
              ->action('View Incident', IncidentResource::getUrl('view', ['record' => $incident]))
              ->line('Please take action as soon as possible.');

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
        $daysUntilDue = now()->diffInDays($this->actionImprovement->due_date, false);
        $isOverdue = $daysUntilDue < 0;

        return [
            'action_improvement_id' => $this->actionImprovement->id,
            'incident_id' => $this->actionImprovement->incident_id,
            'title' => $isOverdue ? 'Action Improvement Overdue' : 'Action Improvement Reminder',
            'body' => '"' . $this->actionImprovement->title . '" is ' .
                ($isOverdue
                    ? abs($daysUntilDue) . ' days overdue'
                    : 'due in ' . $daysUntilDue . ' days'
                ),
            'due_date' => $this->actionImprovement->due_date->format('Y-m-d'),
            'days_until_due' => $daysUntilDue,
            'is_overdue' => $isOverdue,
            'url' => IncidentResource::getUrl('view', ['record' => $this->actionImprovement->incident]),
            'icon' => $isOverdue ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-bell',
            'icon_color' => $isOverdue ? 'danger' : 'warning',
            'type' => $isOverdue ? 'action_improvement_overdue' : 'action_improvement_reminder',
            'format' => 'filament',
        ];
    }
}
