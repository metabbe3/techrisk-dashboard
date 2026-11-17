<?php

declare(strict_types=1);

namespace App\Services\Notion;

use App\Helpers\StringHelper;
use App\Models\Incident;
use Illuminate\Support\Carbon;

/**
 * Single source for Notion → Issue field mapping, shared by the manual CSV
 * importer (App\Filament\Importers\IssuesImporter) and the scheduled
 * sub-page sync (NotionIssueSyncService). Extracted from the importer so
 * the two paths cannot drift (see BUG-008).
 */
class NotionIssueMapper
{
    /** Clean a Notion page name into a dashboard title. */
    public static function cleanTitle(string $name): string
    {
        return str_replace('Summary of Incident - ', '', $name);
    }

    /**
     * The sync's stored title format (owner 2026-11-09): the issue's date
     * prefixes the cleaned name — "2026 Nov 09 - timeout to database". A
     * name already carrying the format is never doubled.
     */
    public static function datedTitle(string $name, Carbon $date): string
    {
        return $date->format('Y M d').' - '.self::stripDatePrefix(self::cleanTitle($name));
    }

    /**
     * Remove ANY leading date prefix from a title — the sync's own
     * "Y M d - " format AND the record's own "9 November 2026 - " style
     * (195/300 record titles, round 7; the live doubled title 2026-11-09).
     * Every title comparison strips it from BOTH sides — a re-synced raw
     * name must still match a prefixed stored title (the page-ID net catches
     * the same page; this keeps the name net working for re-created rows).
     * Repeats so both prefixes stacked strip too. A title that is ONLY a
     * date keeps it; a separator is required so a title that merely mentions
     * a date stays intact.
     */
    public static function stripDatePrefix(string $title): string
    {
        while (true) {
            $stripped = preg_replace(
                '/^(?:\d{4} [A-Za-z]{3} \d{2}|\d{1,2}\s+\w+\s+\d{4}|\d{4}-\d{2}-\d{2}|\w+\s+\d{1,2},?\s+\d{4})\s*[-–—:]\s*/',
                '',
                $title,
            ) ?? $title;

            // No match (preg_replace returns the subject) or nothing left
            // (the title was only a date) — done.
            if ($stripped === $title || blank($stripped)) {
                return $title;
            }

            $title = $stripped;
        }
    }

    /** Parse "Month DD, YYYY" (Notion's format) with a generic fallback. */
    public static function parseNotionDate(string $dateString): Carbon
    {
        try {
            return Carbon::createFromFormat('F d, Y', $dateString)->startOfDay();
        } catch (\Exception) {
            return Carbon::parse($dateString);
        }
    }

    /** Map Notion root-cause classifications to severity codes. */
    public static function mapSeverityToCode(string $classification): string
    {
        $mapping = [
            'Deployment Issues' => 'P1',
            'Infrastructure Issue' => 'P1',
            'Code Bug' => 'P2',
            'Configuration Error' => 'P2',
            'Data Issue' => 'P3',
            'UI Issue' => 'P4',
            'Internal Improving Issue' => 'G',
        ];

        return $mapping[$classification] ?? 'G';
    }

    /**
     * Severity straight from the database's own "Classification by Tech Risk
     * Incident" column (P1–P4 / X1–X4); null when unclassified ("-"/blank/other)
     * so the caller can fall back to mapSeverityToCode().
     */
    public static function mapClassificationToSeverity(?string $classification): ?string
    {
        return $classification !== null && preg_match('/^[PX][1-4]$/', trim($classification)) ? trim($classification) : null;
    }

    /** Generate a unique issue record number (Ymd_IS_xxxx). */
    public static function generateIssueId(): string
    {
        $baseId = date('Ymd').'_IS_';

        do {
            $uniqueId = $baseId.random_int(1000, 9999);
        } while (Incident::where('no', $uniqueId)->exists());

        return $uniqueId;
    }

    /**
     * Case-insensitive/normalized duplicate check against the whole table —
     * Incidents too, not just Issues (owner 2026-10-29: the pre-sync backlog
     * was partly entered as Incidents; a Notion row matching one of those is
     * the same case, not a new Issue).
     */
    public static function findDuplicateByTitle(string $name): ?Incident
    {
        $normalizedTitle = StringHelper::normalizeForComparison(self::stripDatePrefix($name));

        return Incident::query()
            ->get()
            ->first(fn (Incident $incident) => StringHelper::normalizeForComparison(self::stripDatePrefix((string) $incident->title)) === $normalizedTitle);
    }

    /**
     * Normalized titles of every existing incident row (Issues AND Incidents
     * — see findDuplicateByTitle), as a set — one query. The sync service
     * uses this instead of the per-row findDuplicateByTitle() hydration above
     * (which loads the whole table for every row).
     */
    public static function existingNormalizedTitles(): array
    {
        return Incident::query()
            ->pluck('title')
            ->mapWithKeys(fn ($title) => [StringHelper::normalizeForComparison(self::stripDatePrefix((string) $title)) => true])
            ->all();
    }
}
