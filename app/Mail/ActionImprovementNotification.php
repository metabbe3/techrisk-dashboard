<?php

namespace App\Mail;

use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ActionImprovementNotification extends Mailable
{
    use Queueable, SerializesModels;

    public ActionImprovement $actionImprovement;

    /**
     * Create a new message instance.
     */
    public function __construct(ActionImprovement $actionImprovement)
    {
        $this->actionImprovement = $actionImprovement;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action Improvement Notification: ' . $this->actionImprovement->title,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.action-improvement-notification',
            with: [
                'actionImprovement' => $this->actionImprovement,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
