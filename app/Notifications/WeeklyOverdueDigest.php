<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

class WeeklyOverdueDigest extends BaseNotification
{
    public function __construct(
        public readonly Collection $overdueActions,
        public readonly int $totalCount
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = [
            'Here is your weekly summary of overdue action improvements:',
            '',
        ];

        foreach ($this->overdueActions->take(20) as $action) {
            $daysOverdue = now()->diffInDays($action->due_date, false) * -1;
            $lines[] = "**{$action->title}** ({$daysOverdue}d overdue)";
            $lines[] = "Incident: {$action->incident->title} | Due: {$action->due_date->format('Y-m-d')}";
        }

        if ($this->totalCount > 20) {
            $lines[] = '... and '.($this->totalCount - 20).' more.';
        }

        $lines[] = 'This is a weekly automated digest.';

        return $this->buildMailMessage(
            '[Weekly Digest] '.$this->totalCount.' Overdue Action Improvement(s)',
            $lines,
            IncidentResource::getUrl('index'),
            'View All Incidents',
            $notifiable
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->filamentDatabaseFormat([
            'title' => 'Weekly Overdue Digest',
            'body' => $this->totalCount.' overdue action improvement(s) require attention',
            'total_overdue' => $this->totalCount,
            'url' => IncidentResource::getUrl('index'),
            'icon' => 'heroicon-o-document-text',
            'icon_color' => 'warning',
            'type' => 'weekly_overdue_digest',
        ]);
    }
}
