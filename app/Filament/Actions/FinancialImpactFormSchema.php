<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use Closure;
use Filament\Forms\Components\TextInput;

/**
 * The Financial Impact money fields (owner 2026-11-20): they read and
 * display Indonesian money — "1.500.000,00", dot thousands, comma decimals,
 * empty = "0,00" — while the database keeps its existing decimal:2 shape.
 *
 * One parse for every surface: the dehydrator normalizes anything the user
 * types or pastes BEFORE the numeric/min rules run, so a pasted
 * "1.234.567" or "Rp 1.234.567" lands as 1234567.00; the browser-side
 * `trMoneyFormat` (app.js) mirrors the same rules only for the live
 * paste/blur display. Parsing lives HERE — do not hand-roll another one.
 */
class FinancialImpactFormSchema
{
    public static function fields(): array
    {
        return [
            TextInput::make('potential_fund_loss')
                ->prefix('Rp')->default(0)
                ->rule(self::moneyRule())
                ->formatStateUsing(fn ($state) => self::format($state))
                ->dehydrateStateUsing(fn ($state) => self::parse($state))
                ->extraAttributes(self::moneyAttributes()),
            TextInput::make('recovered_fund')
                ->prefix('Rp')->default(0)->required()
                ->rule(self::moneyRule())
                ->formatStateUsing(fn ($state) => self::format($state))
                ->dehydrateStateUsing(fn ($state) => self::parse($state))
                ->extraAttributes(self::moneyAttributes()),
            TextInput::make('fund_loss')
                ->prefix('Rp')->default(0)->required()
                ->rule(self::moneyRule())
                ->formatStateUsing(fn ($state) => self::format($state))
                ->dehydrateStateUsing(fn ($state) => self::parse($state))
                ->extraAttributes(self::moneyAttributes()),
        ];
    }

    /** Filament validates BEFORE dehydrating, so `numeric()` would run on the
     * raw pasted string ("1.234.567" is not is_numeric). The outer closure is
     * Filament's rule factory (evaluated with DI); it returns the Laravel
     * validator closure, which validates the PARSED value — one parse,
     * applied at the validation gate. */
    private static function moneyRule(): Closure
    {
        return fn () => function (string $attribute, mixed $value, Closure $fail) {
            if (! filled($value)) {
                return; // empty → default 0,00 (or the required rule fires first)
            }
            $parsed = self::parse($value);
            if ($parsed === null) {
                $fail("The {$attribute} field must be a number.");
            } elseif ($parsed < 0) {
                $fail("The {$attribute} field must be at least 0.");
            }
        };
    }

    /** id-ID reading: comma = the LAST decimal separator, dots = thousands.
     * A lone dot followed by 1–2 digits reads as an English decimal ("1.50"
     * = 1.5); otherwise dots are thousands ("1.500" = 1500, "1.234.567").
     * Everything but digits, dots and commas is stripped ("Rp " prefixes).
     * No digits at all → null (the field's required/default rules apply). */
    public static function parse(mixed $state): ?float
    {
        $s = preg_replace('/[^0-9.,]/', '', (string) $state);

        if ($s === '' || ! preg_match('/[0-9]/', $s)) {
            return null;
        }

        $dec = '';
        $comma = strrpos($s, ',');
        if ($comma !== false) {
            $int = substr($s, 0, $comma);
            $dec = str_replace(',', '', substr($s, $comma + 1));
        } else {
            $int = $s;
            $dot = strrpos($s, '.');
            if ($dot !== false && strlen(substr($s, $dot + 1)) <= 2) {
                $int = substr($s, 0, $dot);
                $dec = substr($s, $dot + 1);
            }
        }

        $int = str_replace(['.', ','], '', $int);

        return (float) ($int.'.'.str_pad(substr($dec, 0, 2), 2, '0'));
    }

    public static function format(mixed $state): string
    {
        return number_format((float) ($state ?? 0), 2, ',', '.');
    }

    /** Paste + blur only: live reformatting on every keystroke breaks typing
     * (typing "5" after "1,00" would read back as 1.005). No-op until the
     * helper exists (app.js loads deferred). */
    private static function moneyAttributes(): array
    {
        return [
            'x-on:paste' => '$nextTick(() => { if (window.trMoneyFormat) $el.value = window.trMoneyFormat($el.value) })',
            'x-on:blur' => 'if (window.trMoneyFormat) $el.value = window.trMoneyFormat($el.value)',
        ];
    }
}
