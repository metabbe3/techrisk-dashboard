<?php

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
