<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Reminds the PIC (and escalates to admins) that an incident has a fund
 * loss that has not been settled — fund_status is Confirmed loss or
 * Potential recovery and the outstanding amount (potential - recovered) > 0.
 */
class FundLossUnsettledReminder extends IncidentNotification
{
    public function broadcastType(): string
    {
        return 'incident.fund_loss_unsettled';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rp = fn ($amount) => 'Rp '.number_format((float) $amount, 0, ',', '.');

        return $this->templatedMessage(
            subject: "Unsettled fund loss on [{$this->incident->no}] — outstanding {$rp($this->incident->outstanding_fund_loss)}",
            headline: 'Unsettled fund loss',
            intro: 'This incident has a fund loss that has not been settled. Recovery is still outstanding.',
            details: [
                'Incident' => "[{$this->incident->no}] {$this->incident->title}",
                'Fund Status' => $this->incident->fund_status?->label() ?? '-',
                'Potential Loss' => $rp($this->incident->potential_fund_loss),
                'Recovered' => $rp($this->incident->recovered_fund),
                'Outstanding' => $rp($this->incident->outstanding_fund_loss),
                'PIC' => $this->incident->pic?->name ?? 'Unassigned',
            ],
            notifiable: $notifiable,
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->baseDatabasePayload([
            'title' => 'Unsettled fund loss',
            'body' => "{$this->incident->title} — outstanding Rp ".number_format((float) $this->incident->outstanding_fund_loss, 0, ',', '.'),
            'icon' => 'heroicon-o-banknotes',
            'icon_color' => 'danger',
            'type' => 'fund_loss_unsettled_reminder',
        ]);
    }
}
