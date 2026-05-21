<?php

namespace App\Notifications;

class ActionImprovementAssigned extends ActionImprovementNotification
{
    public function broadcastType(): string
    {
        return 'action.improvement.assigned';
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return $this->buildActionMailMessage(
            'Action Improvement Assigned: '.$this->actionImprovement->title,
            [],
            $notifiable,
            'Please complete this action improvement before the due date.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'Action Improvement Assigned',
            'body' => '"'.$this->actionImprovement->title.'" has been assigned to you',
            'icon' => 'heroicon-o-clipboard-document-check',
            'icon_color' => 'info',
            'type' => 'action_improvement_assigned',
        ]);
    }
}
