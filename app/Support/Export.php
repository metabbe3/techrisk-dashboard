<?php

declare(strict_types=1);
namespace App\Support;

/**
 * Small helpers for file-download responses (filenames, etc.).
 * Centralizes the "{slug}[-{date}].{extension}" convention used by the export controllers.
 */
class Export
{
    public static function downloadFilename(string $slug, string $extension, ?string $date = null): string
    {
        return $date !== null
            ? "{$slug}-{$date}.{$extension}"
            : "{$slug}.{$extension}";
    }
}
