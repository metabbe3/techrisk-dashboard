<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class ActionImprovementReminder extends ActionImprovementNotification
{
    public function broadcastType(): string
    {
        return 'action.improvement.reminder';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $daysUntilDue = now()->diffInDays($this->actionImprovement->due_date, false);
        $isOverdue = $daysUntilDue < 0;

        $extraLines = [
            '**Status:** '.ucfirst($this->actionImprovement->status),
        ];

        if ($isOverdue) {
            $extraLines[] = '**'.abs($daysUntilDue).' days overdue**';
        } else {
            $extraLines[] = '**'.$daysUntilDue.' days remaining**';
        }

        return $this->buildActionMailMessage(
            $isOverdue
                ? '[OVERDUE] Action Improvement Required'
                : '[Reminder] Action Improvement Due Soon',
            $extraLines,
            $notifiable,
            'Please take action as soon as possible.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $daysUntilDue = now()->diffInDays($this->actionImprovement->due_date, false);
        $isOverdue = $daysUntilDue < 0;

        return $this->baseDatabasePayload([
            'title' => $isOverdue ? 'Action Improvement Overdue' : 'Action Improvement Reminder',
            'body' => '"'.$this->actionImprovement->title.'" is '.
                ($isOverdue
                    ? abs($daysUntilDue).' days overdue'
                    : 'due in '.$daysUntilDue.' days'
                ),
            'days_until_due' => $daysUntilDue,
            'is_overdue' => $isOverdue,
            'icon' => $isOverdue ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-bell',
            'icon_color' => $isOverdue ? 'danger' : 'warning',
            'type' => $isOverdue ? 'action_improvement_overdue' : 'action_improvement_reminder',
        ]);
    }
}
