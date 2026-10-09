<?php

declare(strict_types=1);

namespace App\Notifications;

class ActionImprovementOverdue extends ActionImprovementNotification
{
    public function via(object $notifiable): array
    {
        // Mail is sent once for the whole PIC group via Support\ReminderMail
        // (owner 2026-10-09) — not per-user through the notification channel.
        return ['database', 'broadcast'];
    }

    public function __construct(
        \App\Models\ActionImprovement $actionImprovement,
        public readonly int $daysOverdue
    ) {
        parent::__construct($actionImprovement);
    }

    public function broadcastType(): string
    {
        return 'action.improvement.overdue';
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return $this->buildActionMailMessage(
            '[URGENT] Action Improvement OVERDUE',
            [
                '**Days Overdue:** '.$this->daysOverdue,
                '**Status:** '.ucfirst($this->actionImprovement->status),
            ],
            $notifiable,
            'Please complete this action improvement as soon as possible.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'Action Improvement OVERDUE',
            'body' => '"'.$this->actionImprovement->title.'" is '.$this->daysOverdue.' days overdue',
            'days_overdue' => $this->daysOverdue,
            'icon' => 'heroicon-o-exclamation-circle',
            'icon_color' => 'danger',
            'type' => 'action_improvement_overdue',
        ]);
    }
}
