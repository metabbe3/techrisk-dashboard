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
            subject: '[Overdue '.$this->daysOverdue.'d] '.$this->actionImprovement->title.' — ['.$this->actionImprovement->incident->no.']',
            headline: 'Action improvement overdue',
            intro: 'This action improvement is '.$this->daysOverdue.' days past its due date and still open. Please complete it as soon as possible.',
            extraDetails: [
                'Days overdue' => $this->daysOverdue,
            ],
            notifiable: $notifiable,
            closingLine: 'Please complete this action improvement as soon as possible.',
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
