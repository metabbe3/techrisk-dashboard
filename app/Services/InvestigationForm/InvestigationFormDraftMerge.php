<?php

declare(strict_types=1);

namespace App\Services\InvestigationForm;

use App\Models\Incident;

/**
 * The ONE place that shapes and merges the shared draft. The intake component
 * (draft load) and the queued extraction job (AI merge) both route through
 * here — a merge-rule change can never drift between them (BUG-005/006/007
 * culture: fix the shared scope, not one call site).
 */
class InvestigationFormDraftMerge
{
    /**
     * The incident's own times fill still-blank datetime fields (draft values
     * win — prefill is blank-only, like the AI merge).
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public static function prefill(array $answers, ?Incident $incident): array
    {
        if ($incident === null) {
            return $answers;
        }

        $prefill = [
            'header.incident_date' => $incident->incident_date,
            'summary.occurred_time' => $incident->incident_date,
            'summary.discovery_time' => $incident->discovered_at,
            'summary.stop_bleeding_time' => $incident->stop_bleeding_at,
        ];

        foreach ($prefill as $key => $value) {
            if ($value === null) {
                continue;
            }

            [$section, $field] = explode('.', $key, 2);

            if (trim((string) ($answers[$section][$field] ?? '')) === '') {
                $answers[$section][$field] = $value->format('Y-m-d\TH:i');
            }
        }

        return $answers;
    }

    /**
     * Extraction fills BLANK fields only — a manual edit or draft value
     * (non-empty) is never erased. Rows are matched by their text, not by
     * position — a re-extract whose rows shifted must not duplicate or
     * overwrite the draft's.
     *
     * @param  array<string, mixed>  $answers
     * @param  array{fields: array<string, string>, chronology: array<int, array{time: string, event: string}>, action_items: array<int, array{item: string, due_date: string, verification: string, owner: string}>}  $out
     * @return array<string, mixed>
     */
    public static function applyExtraction(array $answers, array $out): array
    {
        $answers = self::normalizeRows($answers);

        foreach ($out['fields'] as $key => $value) {
            [$section, $field] = explode('.', $key, 2);

            if (trim((string) ($answers[$section][$field] ?? '')) === '') {
                $answers[$section][$field] = $value;
            }
        }

        $answers['chronology'] = self::mergeListRows($answers['chronology'], $out['chronology'], 'event');
        $answers['action_items'] = self::mergeListRows($answers['action_items'], $out['action_items'], 'item');

        return $answers;
    }

    /**
     * Draft rows come back as strict string shapes — old drafts may carry
     * extra/missing keys; chronology is {time, event}, action items are
     * {item, due_date, verification, owner}.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public static function normalizeRows(array $answers): array
    {
        $answers['chronology'] = array_values(array_map(
            fn (array $row): array => ['time' => (string) ($row['time'] ?? ''), 'event' => (string) ($row['event'] ?? '')],
            (array) ($answers['chronology'] ?? []),
        ));

        $answers['action_items'] = array_values(array_map(
            fn (array $row): array => [
                'item' => (string) ($row['item'] ?? ''),
                'due_date' => (string) ($row['due_date'] ?? ''),
                'verification' => (string) ($row['verification'] ?? ''),
                'owner' => (string) ($row['owner'] ?? ''),
            ],
            (array) ($answers['action_items'] ?? []),
        ));

        return $answers;
    }

    /**
     * Draft rows stay; extracted rows whose identifying text isn't already
     * present are appended (position-based matching duplicated rows and lost
     * shifted ones on a re-extract).
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, array<string, string>>  $incoming
     * @return array<int, array<string, string>>
     */
    private static function mergeListRows(array $rows, array $incoming, string $key): array
    {
        if ($rows === []) {
            return $incoming;
        }

        // An empty "add row" placeholder is not draft content — it would
        // never match a text key, so extracted rows piled up AFTER it. Drop
        // blank rows first, then text-match.
        $rows = array_values(array_filter($rows, fn (array $row): bool => trim((string) ($row[$key] ?? '')) !== ''));

        if ($rows === []) {
            return $incoming;
        }

        $existing = array_map(fn (array $row): string => mb_strtolower(trim((string) ($row[$key] ?? ''))), $rows);

        foreach ($incoming as $row) {
            if (! in_array(mb_strtolower(trim((string) ($row[$key] ?? ''))), $existing, true)) {
                $rows[] = $row;
                $existing[] = mb_strtolower(trim((string) ($row[$key] ?? '')));
            }
        }

        return $rows;
    }
}
