<?php

namespace App\Notifications;

class ActionImprovementEscalated extends ActionImprovementNotification
{
    public function __construct(
        \App\Models\ActionImprovement $actionImprovement,
        public readonly int $daysOverdue
    ) {
        parent::__construct($actionImprovement);
    }

    public function broadcastType(): string
    {
        return 'action.improvement.escalated';
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return $this->buildActionMailMessage(
            '[ESCALATED] Action Improvement '.$this->daysOverdue.' Days Overdue',
            [
                '**Days Overdue:** '.$this->daysOverdue,
            ],
            $notifiable,
            'This is an automated escalation. Please follow up with the assigned PIC.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'Overdue Escalation ('.$this->daysOverdue.'d)',
            'body' => '"'.$this->actionImprovement->title.'" has been overdue for '.$this->daysOverdue.' days',
            'days_overdue' => $this->daysOverdue,
            'icon' => 'heroicon-o-exclamation-triangle',
            'icon_color' => 'danger',
            'type' => 'action_improvement_escalated',
        ]);
    }
}
