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
        return ['database', 'broadcast', 'mail'];
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

    protected function buildMailMessage(string $subject, array $lines, string $actionUrl, string $actionText = 'View Incident', ?object $notifiable = null): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable?->name.',');

        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail->action($actionText, $actionUrl);
    }
}
