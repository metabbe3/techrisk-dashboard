<?php

declare(strict_types=1);

namespace App\Services\Markdown;

use Illuminate\Support\Carbon;

class MarkdownFormatter
{
    /**
     * Clean text by removing excessive whitespace and normalizing line endings.
     */
    public static function clean(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Remove excessive whitespace
        $text = preg_replace('/\s{2,}/', ' ', $text);

        // Clean up line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim($text);
    }

    /**
     * Escape markdown special characters.
     */
    public static function escape(string $text): string
    {
        $chars = ['\\', '`', '*', '_', '{', '}', '[', ']',
            '(', ')', '#', '+', '-', '.', '!', '|'];

        return str_replace($chars, fn ($char) => '\\'.$char, $text);
    }

    /**
     * Format money amount with currency.
     */
    public static function formatMoney(?float $amount, string $currency = 'Rp'): string
    {
        if ($amount === null) {
            return 'N/A';
        }

        return $currency.' '.number_format($amount, 0, ',', '.');
    }

    /**
     * Format date to ISO 8601 format (AI-friendly).
     */
    public static function formatDate($date): string
    {
        if ($date === null) {
            return 'N/A';
        }

        if ($date instanceof Carbon) {
            return $date->format('Y-m-d H:i:s');
        }

        return $date;
    }

    /**
     * Format duration in minutes to human-readable.
     */
    public static function formatDuration(?float $minutes): string
    {
        if ($minutes === null) {
            return 'N/A';
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $mins);
        }

        return sprintf('%dm', $mins);
    }

    /**
     * Format array as markdown list.
     */
    public static function formatList(?array $items, string $prefix = '-'): string
    {
        if (empty($items)) {
            return 'N/A';
        }

        return collect($items)
            ->map(fn ($item) => "{$prefix} {$item}")
            ->implode("\n");
    }

    /**
     * Truncate text to specified length.
     */
    public static function truncate(?string $text, int $length = 200, string $suffix = '...'): string
    {
        if (empty($text)) {
            return '';
        }

        if (strlen($text) <= $length) {
            return $text;
        }

        return rtrim(substr($text, 0, $length)).$suffix;
    }

    /**
     * Convert boolean to yes/no.
     */
    public static function formatBool($value): string
    {
        return $value ? 'Yes' : 'No';
    }

    /**
     * Sanitize filename for safe file system usage.
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove special characters, replace spaces with underscores
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        // Remove multiple consecutive underscores
        $filename = preg_replace('/_+/', '_', $filename);

        // Remove leading/trailing underscores
        return trim($filename, '_');
    }
}
