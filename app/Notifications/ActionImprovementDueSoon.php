<?php

namespace App\Notifications;

class ActionImprovementDueSoon extends ActionImprovementNotification
{
    public function __construct(
        \App\Models\ActionImprovement $actionImprovement,
        public readonly int $daysRemaining
    ) {
        parent::__construct($actionImprovement);
    }

    public function broadcastType(): string
    {
        return 'action.improvement.due.soon';
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return $this->buildActionMailMessage(
            '[Reminder] Action Improvement Due in '.$this->daysRemaining.' Days',
            [
                '**Days Remaining:** '.$this->daysRemaining,
            ],
            $notifiable,
            'Please complete this action improvement before the due date.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'Action Improvement Due Soon',
            'body' => '"'.$this->actionImprovement->title.'" is due in '.$this->daysRemaining.' days',
            'days_remaining' => $this->daysRemaining,
            'icon' => 'heroicon-o-clock',
            'icon_color' => 'warning',
            'type' => 'action_improvement_due_soon',
        ]);
    }
}
