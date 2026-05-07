<?php

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class WeeklyOverdueDigest extends Notification implements ShouldQueue
{
    use Queueable;

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
        $mail = (new MailMessage)
            ->subject('[Weekly Digest] '.$this->totalCount.' Overdue Action Improvement(s)')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Here is your weekly summary of overdue action improvements:')
            ->line('');

        foreach ($this->overdueActions->take(20) as $action) {
            $daysOverdue = now()->diffInDays($action->due_date, false) * -1;
            $mail->line("**{$action->title}** ({$daysOverdue}d overdue)")
                ->line("Incident: {$action->incident->title} | Due: {$action->due_date->format('Y-m-d')}");
        }

        if ($this->totalCount > 20) {
            $mail->line("... and ".($this->totalCount - 20).' more.');
        }

        return $mail
            ->action('View All Incidents', IncidentResource::getUrl('index'))
            ->line('This is a weekly automated digest.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Weekly Overdue Digest',
            'body' => $this->totalCount.' overdue action improvement(s) require attention',
            'total_overdue' => $this->totalCount,
            'url' => IncidentResource::getUrl('index'),
            'icon' => 'heroicon-o-document-text',
            'icon_color' => 'warning',
            'type' => 'weekly_overdue_digest',
            'format' => 'filament',
        ];
    }
}
