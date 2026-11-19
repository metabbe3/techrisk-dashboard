<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One recipient's personal investigation-form link. Subject/body come from
 * the editable templates (Email Settings → Investigation Form) with the
 * placeholders already substituted (values escaped in InvestigationFormSettings::render).
 */
class InvestigationFormRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Plain (untyped) to match the parent Mailable::$subject signature. */
    public $subject;

    public string $body;

    public function __construct(string $subject, string $body)
    {
        $this->subject = $subject;
        $this->body = $body;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            with: [
                'headline' => (string) $this->subject,
                'greeting' => null,
                'intro' => null,
                'bodyHtml' => \Illuminate\Support\Str::markdown($this->body, ['html_input' => 'escape']),
                'actionUrl' => null,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
