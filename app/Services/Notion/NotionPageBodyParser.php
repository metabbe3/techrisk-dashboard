<?php

declare(strict_types=1);

namespace App\Services\Notion;

/**
 * Splits a fetched Notion page body (`notion-fetch` on a page ID) into the
 * Issue content fields. The live tool text (2026-11-04) is a JSON wrapper
 * whose "text" member carries the page: an envelope (preamble, <page> tag,
 * ancestor-path, <properties> JSON, schema trailer) around the content —
 * and the report pages lay their sections out as a two-column TABLE
 * (<tr><td>Label</td><td>value</td></tr>), not headings. Best-effort, never
 * throws: heading-style pages keep working, table pages route by row label,
 * anything unrecognized lands in summary so no content is dropped.
 */
class NotionPageBodyParser
{
    /** Heading/label text → Incident column. Order-insensitive; matched on the cleaned full line. */
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
     * The page body out of a notion-fetch tool text. The hosted MCP server
     * returns a JSON object ({"metadata":…,"text":…}); passthrough when the
     * text is not that shape (plain bodies, tests).
     */
    public static function toolTextBody(string $toolText): string
    {
        $decoded = json_decode($toolText, true);

        return is_array($decoded) && isset($decoded['text']) && is_string($decoded['text'])
            ? $decoded['text']
            : $toolText;
    }

    /**
     * @return array{summary: string, root_cause: string, timeline: string, improvements: string}
     */
    public static function parse(string $body): array
    {
        // The properties block holds Notion's own "AI summary" — the page's
        // lead paragraph. Extracted before the envelope strip drops it.
        $aiSummary = self::extractAiSummary($body);

        $body = self::stripFetchEnvelope($body);

        $buffers = ['summary' => [], 'root_cause' => [], 'timeline' => [], 'improvements' => []];

        if ($aiSummary !== '') {
            $buffers['summary'][] = $aiSummary;
        }

        // Report pages: sections are table rows, label in cell 1, value in
        // cell 2. Known labels route to their column; unknown labels stay in
        // summary as "**Label**\nvalue"; empty values (Attachment) drop.
        if (preg_match_all('/<tr>.*?<\/tr>/s', $body, $rows)) {
            foreach ($rows[0] as $row) {
                if (! preg_match_all('/<td>(.*?)<\/td>/s', $row, $cells)) {
                    continue;
                }

                $label = self::cleanCellValue($cells[1][0] ?? '');
                $value = self::cleanCellValue($cells[1][1] ?? '');

                if ($label === '' || $value === '') {
                    continue;
                }

                $section = self::SECTION_MAP[self::cleanHeading($label)] ?? null;

                if ($section !== null) {
                    $buffers[$section][] = $value;
                } else {
                    $buffers['summary'][] = '**'.$label."**\n".$value;
                }
            }

            $body = str_replace($rows[0], '', $body);
        }

        // Heading-style pages and leftover prose (images, stray lines).
        $current = 'summary';
        $proseLines = [];

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

            if ($current === 'summary') {
                $proseLines[] = $line;
            } else {
                $buffers[$current][] = $line;
            }
        }

        // Prose is ONE chunk (internal line breaks preserved); table rows and
        // the AI summary are separate chunks.
        $prose = trim(implode("\n", $proseLines));

        if ($prose !== '') {
            $buffers['summary'][] = $prose;
        }

        return [
            'summary' => trim(implode("\n\n", array_filter($buffers['summary'], fn ($chunk) => trim($chunk) !== ''))),
            'root_cause' => trim(implode("\n\n", $buffers['root_cause'])),
            // Entries open "HH:MM → " — one per line, guaranteed even when
            // the source cell came through as one run-on paragraph (owner
            // 2026-11-04: "timeline … exact format time and enter").
            'timeline' => self::splitTimelineEntries(trim(implode("\n", $buffers['timeline']))),
            'improvements' => trim(implode("\n\n", $buffers['improvements'])),
        ];
    }

    /** Newline before every "HH:MM → " that is not already at a line start. Idempotent. */
    protected static function splitTimelineEntries(string $timeline): string
    {
        if ($timeline === '') {
            return '';
        }

        return preg_replace('/[ \t]+(?=\d{1,2}:\d{2}\s*→)/u', "\n", $timeline) ?? $timeline;
    }

    /**
     * The notion-fetch envelope around the page content: a preamble line, a
     * <page> wrapper, ancestor-path, <properties>, <iconMetadata>, a
     * <parent-data-source-schema> trailer, table scaffolding — all tool
     * metadata, not page content. Patterns are global (not start-anchored),
     * so multi-chunk bodies (truncated-page subtree drains) work too. Cut-off
     * blocks without their close tag are dropped to end-of-text.
     */
    public static function stripFetchEnvelope(string $body): string
    {
        $body = preg_replace('/Here is the result of .fetch. for the Page[^\n]*\n?/', '', $body) ?? $body;
        $body = preg_replace('/<page\s+url=[^>]*>\n?/', '', $body) ?? $body;
        $body = preg_replace('/<\/page>\n?/', '', $body) ?? $body;

        foreach (['parent-data-source-schema', 'ancestor-path', 'properties', 'iconMetadata', 'colgroup'] as $block) {
            $body = preg_replace('/<'.$block.'\b.*?(?:<\/'.$block.'>|$)/s', '', $body) ?? $body;
        }

        $body = preg_replace('/<\/?table[^>]*>\n?/', '', $body) ?? $body;
        $body = str_replace(['<content>', '</content>', '<p />', '<p/>'], '', $body);

        // Image blocks can't be carried into the text fields — skipped
        // entirely (owner 2026-11-04: no [image] placeholders).
        return preg_replace('/<image\s+source="[^"]*"\s*\/>\n?/', '', $body) ?? $body;
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

    /** A table cell's value: <br /> → newlines, tags stripped, lines trimmed, blanks dropped. */
    protected static function cleanCellValue(string $raw): string
    {
        $value = preg_replace('/<br\s*\/?>/', "\n", $raw) ?? $raw;
        $value = strip_tags($value);

        $lines = array_map('trim', explode("\n", $value));

        return trim(implode("\n", array_filter($lines, fn (string $line) => $line !== '')));
    }

    /** Notion's own "AI summary" page property, tags stripped. */
    protected static function extractAiSummary(string $body): string
    {
        if (! preg_match('/<properties>(.*?)<\/properties>/s', $body, $match)) {
            return '';
        }

        $properties = json_decode(trim($match[1]), true);
        $summary = $properties['AI summary'] ?? null;

        if (! is_string($summary) || trim($summary) === '') {
            return '';
        }

        return trim(preg_replace('/<\/?b>/', '', $summary) ?? $summary);
    }
}
