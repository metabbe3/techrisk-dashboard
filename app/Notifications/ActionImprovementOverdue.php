<?php

namespace App\Notifications;

class ActionImprovementOverdue extends ActionImprovementNotification
{
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
