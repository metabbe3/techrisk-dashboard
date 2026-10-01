<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime mail activation — the single place that decides whether Netcore
 * sends email and what From address everything uses. Applied on boot and
 * after the Email Settings page saves, so activation needs NO .env editing
 * (owner request 2026-10-01: "Set MAIL_MAILER=netcore in .env" was not
 * actionable from the dashboard).
 *
 * Rules:
 * - mail.default becomes 'netcore' only when the netcore_enabled toggle is
 *   on AND an API key exists (from settings or env). Otherwise the env
 *   default (log) stays — nothing sends.
 * - mail.from.address/name always come from settings so the owner can edit
 *   the sender in the dashboard. Transport kill-switch still suppresses
 *   instantly on every send.
 */
class MailSettings
{
    public static function apply(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                self::applyFromDefaults();

                return;
            }

            $enabled = (bool) Setting::get('netcore_enabled', true);
            $apiKey = Setting::get('netcore_api_key', config('mail.mailers.netcore.api_key'));

            if ($enabled && filled($apiKey)) {
                config(['mail.default' => 'netcore']);
            }

            config([
                'mail.from.address' => Setting::get('mail_from_address', 'noreply-techrisk@dana.id'),
                'mail.from.name' => Setting::get('mail_from_name', config('mail.from.name')),
            ]);
        } catch (\Throwable) {
            // Boot-safe: a missing/migrating settings table must never take
            // the whole app down — fall back to env defaults.
            self::applyFromDefaults();
        }
    }

    private static function applyFromDefaults(): void
    {
        config(['mail.from.address' => 'noreply-techrisk@dana.id']);
    }
}
