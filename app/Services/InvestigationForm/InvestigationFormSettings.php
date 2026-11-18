<?php

declare(strict_types=1);

namespace App\Services\InvestigationForm;

use App\Models\Setting;

/**
 * Typed accessors for every investigation-form setting (Setting KV store,
 * cached). Each value has a code default so an empty setting never breaks a
 * send. The Email Settings page persists through the same keys.
 */
class InvestigationFormSettings
{
    public const KEY_EXPIRY_DAYS = 'investigation_form_expiry_days';

    public const KEY_MAX_RECIPIENTS = 'investigation_form_max_recipients';

    public const KEY_PREPARE_LIST = 'investigation_form_prepare_list';

    public const KEY_REQUIRED_FIELDS = 'investigation_form_required_fields';

    public const KEY_REQUEST_SUBJECT = 'investigation_form_request_subject';

    public const KEY_REQUEST_BODY = 'investigation_form_request_body';

    public const KEY_REMINDER_SUBJECT = 'investigation_form_reminder_subject';

    public const KEY_REMINDER_BODY = 'investigation_form_reminder_body';

    public const KEY_REMINDERS_ENABLED = 'investigation_form_reminders_enabled';

    public const KEY_REMINDER_INTERVAL_DAYS = 'investigation_form_reminder_interval_days';

    public const KEY_FINAL_REMINDER_DAYS = 'investigation_form_final_reminder_days';

    /** {days_left} is a reminder-only placeholder. */
    public const PLACEHOLDERS = ['incident_no', 'incident_title', 'incident_date', 'team', 'recipient_email', 'link', 'expires_at', 'note', 'prepare_list'];

    public const REMINDER_PLACEHOLDERS = ['days_left'];

    public const DEFAULT_REQUEST_SUBJECT = 'Investigation form request — {incident_no} {incident_title}';

    public const DEFAULT_REQUEST_BODY = <<<'MD'
Hello {recipient_email},

You are requested to fill the incident investigation form for **{incident_no} — {incident_title}** ({incident_date}) on behalf of the **{team}** team.

{note}

**Please prepare:**
{prepare_list}

**Your personal link (valid until {expires_at}):**
{link}

The link is personal to your email address. Your team shares one draft — you can save and continue later. Submitting the form closes the links for the whole team.
MD;

    public const DEFAULT_REMINDER_SUBJECT = 'Reminder: investigation form — {incident_no} {incident_title}';

    public const DEFAULT_REMINDER_BODY = <<<'MD'
Hello {recipient_email},

A friendly reminder: the incident investigation form for **{incident_no} — {incident_title}** ({incident_date}) is still waiting. The form expires on **{expires_at}** — {days_left} day(s) left.

Your team shares one draft — any progress you already saved is still there. Please use this latest link (previous links no longer work):

{link}

{note}
MD;

    public static function expiryDays(): int
    {
        return max(1, min(60, (int) Setting::get(self::KEY_EXPIRY_DAYS, 14)));
    }

    public static function maxRecipients(): int
    {
        return max(1, min(50, (int) Setting::get(self::KEY_MAX_RECIPIENTS, 10)));
    }

    /** @return array<int, string> */
    public static function prepareList(): array
    {
        $raw = (string) Setting::get(self::KEY_PREPARE_LIST, implode("\n", [
            'The incident timeline (what happened, in order, with times)',
            'Business flow and impact description',
            'Answers for your process area (development/testing/deployment/monitoring/contingency)',
            'Any supporting screenshot or document (optional, upload on the form)',
        ]));

        return array_values(array_filter(array_map('trim', explode("\n", $raw))));
    }

    /**
     * Flat field keys required to submit. Default: every header + summary
     * field and at least one chronology row ("chronology"). The Q&A sections
     * and action items are optional by default. The Statement of Truth
     * (consent step) is always required and not configurable.
     *
     * @return array<int, string>
     */
    public static function requiredFields(): array
    {
        $default = array_merge(
            array_map(fn (string $k): string => "header.{$k}", array_keys(InvestigationFormSchema::SECTIONS['header']['fields'])),
            array_map(fn (string $k): string => "summary.{$k}", array_keys(InvestigationFormSchema::SECTIONS['summary']['fields'])),
            ['chronology'],
        );

        $stored = Setting::get(self::KEY_REQUIRED_FIELDS);

        if (! is_array($stored) || $stored === []) {
            return $default;
        }

        // Unknown keys (schema changed since) are dropped.
        $valid = array_keys(InvestigationFormSchema::flatFields());

        return array_values(array_intersect(array_map('strval', $stored), array_merge($valid, ['chronology'])));
    }

    public static function remindersEnabled(): bool
    {
        return (bool) Setting::get(self::KEY_REMINDERS_ENABLED, true);
    }

    public static function reminderIntervalDays(): int
    {
        return max(1, min(14, (int) Setting::get(self::KEY_REMINDER_INTERVAL_DAYS, 7)));
    }

    public static function finalReminderDays(): int
    {
        return max(0, (int) Setting::get(self::KEY_FINAL_REMINDER_DAYS, 2));
    }

    public static function requestSubject(): string
    {
        return (string) Setting::get(self::KEY_REQUEST_SUBJECT, self::DEFAULT_REQUEST_SUBJECT);
    }

    public static function requestBody(): string
    {
        return (string) Setting::get(self::KEY_REQUEST_BODY, self::DEFAULT_REQUEST_BODY);
    }

    public static function reminderSubject(): string
    {
        return (string) Setting::get(self::KEY_REMINDER_SUBJECT, self::DEFAULT_REMINDER_SUBJECT);
    }

    public static function reminderBody(): string
    {
        return (string) Setting::get(self::KEY_REMINDER_BODY, self::DEFAULT_REMINDER_BODY);
    }

    /**
     * Substitute {placeholders}. Values are HTML-escaped first (the mail body
     * renders as HTML), unknown placeholders are left as-is on send and
     * rejected at save time by the settings page.
     *
     * @param  array<string, ?string>  $data
     */
    public static function render(string $template, array $data): string
    {
        $rendered = $template;

        foreach ($data as $key => $value) {
            $rendered = str_replace(
                '{'.$key.'}',
                e((string) ($value ?? '')),
                $rendered,
            );
        }

        return $rendered;
    }

    /**
     * Unknown {tokens} left in a template after substitution of the known
     * set — the settings page refuses a save that contains any.
     *
     * @return array<int, string>
     */
    public static function unknownPlaceholders(string $template, bool $isReminder): array
    {
        $allowed = $isReminder
            ? array_merge(self::PLACEHOLDERS, self::REMINDER_PLACEHOLDERS)
            : self::PLACEHOLDERS;

        preg_match_all('/\{([a-z_]+)\}/', $template, $matches);

        return array_values(array_unique(array_diff($matches[1], $allowed)));
    }
}
