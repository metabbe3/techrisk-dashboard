<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Wraps a notification MailMessage as a real Mailable so a whole PIC group
 * gets ONE email (multiple TOs — one Netcore API call) instead of one mail
 * per user (owner rule 2026-10-09). Sent via Support\ReminderMail.
 */
class GroupNotificationMail extends Mailable
{
    public function __construct(private readonly MailMessage $message) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) ($this->message->subject ?? config('app.name')),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: (string) $this->message->render(),
        );
    }
}
