<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ActionImprovement;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A worker submitted proof and it awaits approval (owner 2026-11-20). The
 * in-app half goes per-user through notify(); the EMAIL goes out ONCE for
 * the whole approver group via Support\ReminderMail (multi-TO, owner
 * 2026-10-09) — so via() deliberately excludes mail.
 */
class ActionImprovementProofSubmitted extends ActionImprovementNotification
{
    /**
     * @param  array<int, string>  $fileNames  original filenames of the submitted proof files
     * @param  array<int, string>  $linkUrls  chat / Notion links submitted as proof
     */
    public function __construct(
        ActionImprovement $actionImprovement,
        public readonly string $submitterName,
        public readonly array $fileNames = [],
        public readonly array $linkUrls = [],
    ) {
        parent::__construct($actionImprovement);
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $items = [
            ...array_map(fn (string $name): string => "Proof file: **{$name}**", $this->fileNames),
            ...array_map(fn (string $url): string => "Proof link: {$url}", $this->linkUrls),
        ];

        return $this->buildActionMailMessage(
            subject: '[Proof submitted] '.$this->actionImprovement->title.' — ['.$this->actionImprovement->incident->no.']',
            headline: 'Proof awaiting approval',
            intro: "{$this->submitterName} submitted proof for the action below and it needs your approval.",
            extraLines: $items,
            notifiable: $notifiable,
            closingLine: 'Open the action items and use Approve Proof to close it.',
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'Proof Awaiting Approval',
            'body' => "{$this->submitterName} submitted proof for \"{$this->actionImprovement->title}\"",
            'icon' => 'heroicon-o-check-badge',
            'icon_color' => 'success',
            'type' => 'action_improvement_proof_submitted',
        ]);
    }
}
