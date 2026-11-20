<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A submitter's proof was approved (owner 2026-11-20): the action is marked
 * Done and reminders/escalation stopped. Per-user branded mail — the
 * ActionImprovementAssigned precedent (default BaseNotification::via).
 */
class ActionImprovementProofApproved extends ActionImprovementNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildActionMailMessage(
            subject: '[Proof approved] '.$this->actionImprovement->title.' — ['.$this->actionImprovement->incident->no.']',
            headline: 'Proof approved',
            intro: 'Your proof was approved and the action is marked Done — reminders and escalation have stopped.',
            notifiable: $notifiable,
            closingLine: 'No further action is needed.',
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'Proof Approved',
            'body' => "Your proof for \"{$this->actionImprovement->title}\" was approved — the action is Done",
            'icon' => 'heroicon-o-check-badge',
            'icon_color' => 'success',
            'type' => 'action_improvement_proof_approved',
        ]);
    }
}
