<?php

namespace App\Notifications;

use App\Models\ActionImprovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionImprovementReminder extends Notification
{
    use Queueable;

    public $actionImprovement;

    /**
     * Create a new notification instance.
     */
    public function __construct(ActionImprovement $actionImprovement)
    {
        $this->actionImprovement = $actionImprovement;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/incidents/' . $this->actionImprovement->incident_id);
        $incident = $this->actionImprovement->incident;

        return (new MailMessage)
            ->subject('[Action Improvement] Reminder - ' . $incident->title)
            ->line('This is a reminder for an action improvement on the following incident:')
            ->line('Incident: ' . $incident->title)
            ->line('Summary: ' . $incident->summary)
            ->line('---')
            ->line('Action Improvement: ' . $this->actionImprovement->title)
            ->line('Detail: ' . $this->actionImprovement->detail)
            ->line('Due Date: ' . $this->actionImprovement->due_date)
            ->action('View Incident', $url)
            ->line('Thank you for your attention to this matter.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
