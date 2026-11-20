<?php

declare(strict_types=1);

namespace App\Actions;

use App\Mail\InvestigationFormRequestMail;
use App\Models\Incident;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use App\Services\InvestigationForm\InvestigationFormSettings;
use App\Support\InvestigationFormMailFactory;
use Illuminate\Support\Facades\Mail;

/**
 * Creates (or reopens) the per-team investigation form request and emails
 * each NEW recipient their own unique SSO-only link. Existing non-revoked
 * recipients get no second mail — the Resend action does that. Tokens are
 * derived (InvestigationFormRecipient::linkToken()), never rotated: the same
 * link re-derives on every send until submit/revoke/expiry.
 *
 * @return array{request: InvestigationFormRequest, mailed: array<string, string>, skipped: array<int, string>}
 *                                                                                                              mailed = email => plain link (for the admin's one-time copy fallback)
 */
class RequestInvestigationForm
{
    /**
     * @param  array<int, string>  $emails
     * @param  array<int, string>  $skippedOut  out-param for existing/revoked-duplicate emails
     */
    public function handle(Incident $incident, string $team, array $emails, ?string $note, User $creator): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            fn (string $email): string => mb_strtolower(trim($email)),
            $emails,
        ))));

        $invalid = array_values(array_filter($emails, fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) === false));

        if ($invalid !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'recipients' => 'Not valid email addresses: '.implode(', ', $invalid),
            ]);
        }

        $max = InvestigationFormSettings::maxRecipients();

        if (count($emails) > $max) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'recipients' => "Maximum {$max} recipients per team request.",
            ]);
        }

        $request = InvestigationFormRequest::firstOrCreate(
            [
                'incident_id' => $incident->id,
                'team' => $team,
            ],
            [
                'status' => InvestigationFormRequest::STATUS_PENDING,
                'expires_at' => now()->addDays(InvestigationFormSettings::expiryDays()),
                'created_by' => $creator->id,
            ],
        );

        // Reopen an expired/revoked request and extend its window.
        if (! $request->isOpen()) {
            $request->update([
                'status' => InvestigationFormRequest::STATUS_PENDING,
                'expires_at' => now()->addDays(InvestigationFormSettings::expiryDays()),
            ]);
        }

        $mailed = [];
        $skipped = [];

        foreach (array_unique($emails) as $email) {
            $email = mb_strtolower(trim($email));

            if ($email === '') {
                continue;
            }

            $existing = $request->recipients()->where('email', $email)->first();

            if ($existing !== null && ! $existing->isRevoked()) {
                $skipped[] = $email;

                continue;
            }

            $recipient = $existing ?? new InvestigationFormRecipient([
                'request_id' => $request->id,
                'email' => $email,
            ]);
            // Tokens are derived from request_id|email (never rotated) — the
            // same link re-derives on every send; linkToken() re-keys any
            // pre-de-rotation hash when its mail goes out.
            $plainToken = $existing !== null ? $recipient->linkToken() : InvestigationFormRecipient::tokenFor($request->id, $email);
            $recipient->token_hash = InvestigationFormRecipient::hashToken($plainToken);
            $recipient->revoked_at = null;
            $recipient->emailed_at = now();
            $recipient->save();

            ['mail' => $mail, 'link' => $link] = InvestigationFormMailFactory::requestMail($recipient, $plainToken, $note);

            /** @var InvestigationFormRequestMail $mail */
            Mail::to($email)->queue($mail);

            $mailed[$email] = $link;
        }

        return ['request' => $request->refresh(), 'mailed' => $mailed, 'skipped' => $skipped];
    }
}
