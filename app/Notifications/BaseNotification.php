<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Mail body for a combined group email (Support\ReminderMail). Builders
     * only use $notifiable for the greeting name, so an anonymous object
     * carrying the joined names works unchanged.
     *
     * @param  array<int, \App\Models\User>  $recipients
     */
    public function toMailForGroup(array $recipients): MailMessage
    {
        $names = implode(', ', array_map(fn ($user) => $user->name, $recipients));

        return $this->toMail(new class($names)
        {
            public function __construct(public readonly string $name) {}
        });
    }

    protected function filamentDatabaseFormat(array $overrides): array
    {
        return array_merge([
            'format' => 'filament',
            'type' => 'info',
        ], $overrides);
    }

    /**
     * Branded (HTML) email through the shared layout
     * (emails/notification.blade.php). Every portal notification renders
     * through here — the white card + DANA-blue header look.
     *
     * @param  array<int, string>  $lines  paragraphs; **bold** supported
     * @param  array<string, string>  $details  label => value rows
     */
    protected function brandedMessage(
        string $subject,
        string $headline,
        array $lines = [],
        ?string $intro = null,
        array $details = [],
        ?string $actionText = null,
        ?string $actionUrl = null,
        ?object $notifiable = null,
        ?string $secondaryText = null,
        ?string $secondaryUrl = null,
    ): MailMessage {
        return (new MailMessage)
            ->subject($subject)
            ->view('emails.notification', [
                'greeting' => $notifiable !== null ? 'Hello '.$notifiable->name.',' : null,
                'headline' => $headline,
                'intro' => $intro,
                'lines' => $lines,
                'details' => $details,
                'actionText' => $actionText,
                'actionUrl' => $actionUrl,
                'secondaryText' => $secondaryText,
                'secondaryUrl' => $secondaryUrl,
            ]);
    }

    /**
     * Simple lines + one button — the workhorse for the trigger-style
     * notifications (status changed, PIC assigned, announcements, …).
     *
     * @param  array<int, string>  $lines
     */
    protected function buildMailMessage(string $subject, array $lines, string $actionUrl, string $actionText = 'View Incident', ?object $notifiable = null): MailMessage
    {
        return $this->brandedMessage(
            subject: $subject,
            headline: $subject,
            lines: $lines,
            actionText: $actionText,
            actionUrl: $actionUrl,
            notifiable: $notifiable,
        );
    }
}
