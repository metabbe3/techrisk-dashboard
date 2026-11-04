<?php

declare(strict_types=1);

namespace App\Services\Notion;

/**
 * Splits a fetched Notion page body (`notion-fetch` on a page ID) into the
 * Issue content fields. Best-effort: known section headings route their text
 * to root_cause / timeline / improvements; everything else (including
 * unknown sections, with their headings kept) accumulates in summary, so no
 * content is ever dropped. Never throws — any body shape degrades to a
 * summary-only result.
 */
class NotionPageBodyParser
{
    /** Heading text → Incident column. Order-insensitive; matched on the cleaned full line. */
    protected const SECTION_MAP = [
        'root cause' => 'root_cause',
        'root cause analysis' => 'root_cause',
        'timeline' => 'timeline',
        'chronology' => 'timeline',
        'incident timeline and chronology' => 'timeline',
        'incident timeline & chronology' => 'timeline',
        'action taken' => 'improvements',
        'actions taken' => 'improvements',
        'action plan' => 'improvements',
        'improvement' => 'improvements',
        'improvements' => 'improvements',
    ];

    /**
     * @return array{summary: string, root_cause: string, timeline: string, improvements: string}
     */
    public static function parse(string $body): array
    {
        $buffers = ['summary' => [], 'root_cause' => [], 'timeline' => [], 'improvements' => []];
        $current = 'summary';

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $section = self::sectionFor($line);

            if ($section !== null) {
                // Known heading: consumed, its following lines are its section.
                $current = $section;

                continue;
            }

            if (self::looksLikeHeading($line)) {
                // Unknown heading (Date Time, Service Impact, …): stays in the
                // text as summary content, labelled by its own heading line.
                $current = 'summary';
            }

            $buffers[$current][] = $line;
        }

        return [
            'summary' => trim(implode("\n", $buffers['summary'])),
            'root_cause' => trim(implode("\n", $buffers['root_cause'])),
            'timeline' => trim(implode("\n", $buffers['timeline'])),
            'improvements' => trim(implode("\n", $buffers['improvements'])),
        ];
    }

    /** The section a line opens, or null when it is not a known heading. */
    protected static function sectionFor(string $line): ?string
    {
        return self::SECTION_MAP[self::cleanHeading($line)] ?? null;
    }

    /**
     * Heading match key: markdown hashes/bold/colons stripped from both ends,
     * lowercased, collapsed. "## **Root Cause:**" → "root cause".
     */
    protected static function cleanHeading(string $line): string
    {
        $clean = trim(preg_replace('/^[\s#*_>:-]+|[\s#*_>:-]+$/u', '', $line) ?? '');

        return mb_strtolower(preg_replace('/\s+/u', ' ', $clean) ?? '');
    }

    /** A line that opens a section we do NOT map — markdown heading or short bold label. */
    protected static function looksLikeHeading(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^#{1,6}\s+\S/', $trimmed)) {
            return true;
        }

        // Short bold label ("**Service Impact**", "**Attachment**") — up to 6 words.
        return (bool) preg_match('/^\*\*[^*]+\*\*:?$/', $trimmed)
            && str_word_count($trimmed) <= 8;
    }
}
