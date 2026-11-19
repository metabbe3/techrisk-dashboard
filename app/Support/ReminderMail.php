<?php

declare(strict_types=1);

namespace App\Support;

use App\Mail\GroupNotificationMail;
use App\Models\User;
use App\Notifications\BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Sends reminder emails as ONE message to every mail-eligible recipient
 * (multiple TOs — one Netcore API call) instead of one email per user
 * (owner rule 2026-10-09). The per-user in-app notification (database +
 * broadcast) is sent separately by the caller via $user->notify().
 */
class ReminderMail
{
    /**
     * @param  iterable<User>|Collection<int, User>  $recipients
     * @return int mail-eligible recipients actually on the send (0 = no
     *             email went out — global kill-switch or everyone's
     *             preference is off)
     */
    public static function send(iterable|Collection $recipients, BaseNotification $notification): int
    {
        // Kill-switch: report 0 so callers (recordReminderSent, manual
        // buttons) never stamp "reminded" for an email that was suppressed.
        if (! MailSettings::enabled()) {
            return 0;
        }

        $eligible = collect($recipients)
            ->filter()
            ->unique('id')
            ->filter(fn (User $user) => $user->mailPreferenceAllows($notification))
            ->values();

        if ($eligible->isEmpty()) {
            return 0;
        }

        Mail::to($eligible->all())
            ->send(new GroupNotificationMail($notification->toMailForGroup($eligible->all())));

        return $eligible->count();
    }
}
