<?php

namespace App\Helpers;

class StringHelper
{
    /**
     * Normalize a string for fuzzy comparison.
     *
     * Performs:
     * - Remove "Summary of Incident - " prefix (case-insensitive)
     * - Trim leading/trailing whitespace
     * - Normalize multiple spaces to single space
     * - Convert to lowercase for case-insensitive comparison
     *
     * @param string $input The raw input string
     * @return string The normalized string
     */
    public static function normalizeForComparison(string $input): string
    {
        // Remove common Notion prefix if present (case-insensitive, case-folded first)
        $cleaned = mb_strtolower($input);
        $cleaned = str_replace('summary of incident - ', '', $cleaned);

        // Trim whitespace
        $cleaned = trim($cleaned);

        // Normalize multiple spaces to single space
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);

        return $cleaned;
    }

    /**
     * Check if two titles are considered duplicates using fuzzy matching.
     *
     * @param string $title1 First title
     * @param string $title2 Second title
     * @return bool True if titles are considered duplicates
     */
    public static function isDuplicateTitle(string $title1, string $title2): bool
    {
        return self::normalizeForComparison($title1) === self::normalizeForComparison($title2);
    }
}
