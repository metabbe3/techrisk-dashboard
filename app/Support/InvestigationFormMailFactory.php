<?php

declare(strict_types=1);

namespace App\Support;

use App\Mail\InvestigationFormRequestMail;
use App\Models\InvestigationFormRecipient;
use App\Services\InvestigationForm\InvestigationFormSettings;

/**
 * Builds the intake emails (request + reminder) from the editable templates.
 * One place so the admin action and the reminder command stay in sync.
 * The plain token is never stored — it is passed in by whoever just rotated
 * it and consumed only by this mail (plus the admin's one-time copy link).
 */
class InvestigationFormMailFactory
{
    /**
     * @return array{mail: InvestigationFormRequestMail, link: string}
     */
    public static function requestMail(InvestigationFormRecipient $recipient, string $plainToken, ?string $note): array
    {
        return self::build(
            $recipient,
            $plainToken,
            $note ?? '',
            InvestigationFormSettings::requestSubject(),
            InvestigationFormSettings::requestBody(),
            false,
        );
    }

    /**
     * @return array{mail: InvestigationFormRequestMail, link: string}
     */
    public static function reminderMail(InvestigationFormRecipient $recipient, string $plainToken): array
    {
        return self::build(
            $recipient,
            $plainToken,
            '',
            InvestigationFormSettings::reminderSubject(),
            InvestigationFormSettings::reminderBody(),
            true,
        );
    }

    /**
     * @return array{mail: InvestigationFormRequestMail, link: string}
     */
    private static function build(InvestigationFormRecipient $recipient, string $plainToken, string $note, string $subject, string $body, bool $isReminder): array
    {
        $request = $recipient->request;
        $incident = $request->incident;
        $link = route('investigation-form.intake', ['token' => $plainToken]);

        $daysLeft = '';
        if ($isReminder && $request->expires_at !== null) {
            $daysLeft = (string) max(0, now()->startOfDay()->diffInDays($request->expires_at->startOfDay(), false));
        }

        $data = [
            'incident_no' => (string) $incident?->no,
            'incident_title' => (string) $incident?->title,
            'incident_date' => $incident?->incident_date?->format('Y-m-d') ?? '',
            'team' => $request->team,
            'recipient_email' => $recipient->email,
            'link' => $link,
            'expires_at' => $request->expires_at?->format('Y-m-d H:i') ?? '',
            'note' => $note,
            'days_left' => $daysLeft,
            'prepare_list' => implode("\n", array_map(fn (string $item): string => '- '.$item, InvestigationFormSettings::prepareList())),
        ];

        return [
            'mail' => new InvestigationFormRequestMail(
                InvestigationFormSettings::render($subject, $data),
                InvestigationFormSettings::render($body, $data),
            ),
            'link' => $link,
        ];
    }
}
