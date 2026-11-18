<?php

declare(strict_types=1);

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
            subject: '[Assigned] '.$this->actionImprovement->title.' — ['.$this->actionImprovement->incident->no.']',
            headline: 'Action improvement assigned',
            intro: 'A new action improvement has been assigned to you on the incident below. Please review the detail and start the work.',
            closingLine: 'Please complete this action improvement before the due date.',
            notifiable: $notifiable,
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
