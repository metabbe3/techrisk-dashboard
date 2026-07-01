<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Serialize dates in the application timezone (Asia/Jakarta, GMT+7) with the
 * offset, instead of Laravel's default UTC ("…Z"). Keeps the API's datetime
 * output consistent with what the Filament dashboard displays (app tz).
 *
 * Affects JSON/array serialization only (API Resources); Filament formats dates
 * directly from the Carbon instance and is unaffected.
 */
trait SerializesDatesInAppTimezone
{
    protected function serializeDate(\DateTimeInterface $date): string
    {
        $carbon = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return $carbon->setTimezone(config('app.timezone', 'Asia/Jakarta'))->toIso8601String();
    }
}
