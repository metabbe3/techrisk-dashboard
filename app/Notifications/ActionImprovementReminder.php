<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class ActionImprovementReminder extends ActionImprovementNotification
{
    public function via(object $notifiable): array
    {
        // Mail is sent once for the whole PIC group via Support\ReminderMail
        // (owner 2026-10-09) — not per-user through the notification channel.
        return ['database', 'broadcast'];
    }

    public function broadcastType(): string
    {
        return 'action.improvement.reminder';
    }

    /**
     * TBD rows (null due date) return null — diffInDays(null) would parse
     * null as now() and report "0 days remaining".
     */
    private function daysUntilDue(): ?int
    {
        return $this->actionImprovement->due_date === null
            ? null
            : (int) round(now()->diffInDays($this->actionImprovement->due_date, false));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $daysUntilDue = $this->daysUntilDue();
        $isOverdue = $daysUntilDue !== null && $daysUntilDue < 0;

        $extraDetails = [];
        $intro = 'This action improvement still needs work from you.';

        if ($daysUntilDue === null) {
            $intro = 'This action improvement has no due date yet (TBD). Please set a date or start the work.';
        } elseif ($isOverdue) {
            $extraDetails['Days overdue'] = abs($daysUntilDue);
            $intro = 'This action improvement is '.abs($daysUntilDue).' days past its due date and still open.';
        } else {
            $extraDetails['Days remaining'] = $daysUntilDue;
            $intro = 'This action improvement is due in '.$daysUntilDue.' days.';
        }

        return $this->buildActionMailMessage(
            subject: ($isOverdue ? '[Overdue '.abs((int) $daysUntilDue).'d] ' : '[Reminder] ').$this->actionImprovement->title.' — ['.$this->actionImprovement->incident->no.']',
            headline: $isOverdue ? 'Action improvement overdue' : 'Action improvement reminder',
            intro: $intro,
            extraDetails: $extraDetails,
            notifiable: $notifiable,
            closingLine: 'Please take action as soon as possible.',
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $daysUntilDue = $this->daysUntilDue();
        $isOverdue = $daysUntilDue !== null && $daysUntilDue < 0;

        return $this->baseDatabasePayload([
            'title' => $isOverdue ? 'Action Improvement Overdue' : 'Action Improvement Reminder',
            'body' => '"'.$this->actionImprovement->title.'" is '.
                ($daysUntilDue === null
                    ? 'due TBD (no date set)'
                    : ($isOverdue
                        ? abs($daysUntilDue).' days overdue'
                        : 'due in '.$daysUntilDue.' days'
                    )
                ),
            'days_until_due' => $daysUntilDue,
            'is_overdue' => $isOverdue,
            'icon' => $isOverdue ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-bell',
            'icon_color' => $isOverdue ? 'danger' : 'warning',
            'type' => $isOverdue ? 'action_improvement_overdue' : 'action_improvement_reminder',
        ]);
    }
}
