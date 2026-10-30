<?php

declare(strict_types=1);

namespace App\Services\Notion;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled sync: watches the configured Notion database (Setting
 * "notion_watch_page_id") and imports new entries as Issue records
 * (classification = Issue). Each run reads only rows created at/after the
 * last processed row (Setting "notion_sync_watermark" — the raw createdTime
 * string of the newest row already seen), so Notion is never re-scanned in
 * full. Page-ID AND normalized-title dedupe remain as safety nets (first-run
 * overlap with the manual backlog, rows sharing the watermark's second).
 *
 * Deleting an imported Issue from the dashboard is PERMANENT for the normal
 * sync (owner 2026-10-29) — tombstones are only revisited by an explicit
 * full sync (restoreDeleted: true, the "Full sync" checkbox), which
 * re-imports them by direct page lookup. Permanent removal is still deleting
 * the row in Notion; a full sync drops the tombstone of a page that no
 * longer exists there. The full sync's restore set is the operator's choice
 * (2026-10-30): the "Sync now" modal lists the restorable deleted issues
 * (restorableIssues()) and restores only the ticked pages — restorePageIds
 * null means no pick was made (the console/scheduler path) and restores all.
 *
 * Live shape (verified 2026-10-27 against mcp.notion.com): `notion-fetch` on
 * a database returns its SCHEMA, not rows — including the queryable
 * `collection://<uuid>` table name. Rows come from `notion-query-data-sources`
 * with the arguments wrapped in a `data` object:
 *   {"data": {"data_source_urls": ["collection://..."], "query": "SELECT ..."}}
 * returning {"results": [{url, createdTime, Name, "date:Date of Incident:start",
 * "Classification by Tech Risk Incident", "Root Cause Classification"}, ...]}.
 */
class NotionIssueSyncService
{
    public const WATCH_KEY = 'notion_watch_page_id';

    public const LAST_SYNC_AT_KEY = 'notion_last_sync_at';

    public const LAST_SYNC_STATUS_KEY = 'notion_last_sync_status';

    public const WATERMARK_KEY = 'notion_sync_watermark';

    /**
     * Query page size — and the real sync's drain rate (the watermark picks
     * the rest up over the following runs). 100 = the Notion engine's
     * observed per-query row cap (prod 2026-10-30 returned exactly 100 of a
     * larger database); asking for more never yields more.
     */
    protected const SCAN_LIMIT = 100;

    /** Dry-run pagination runaway guard (25 pages × SCAN_LIMIT rows). */
    protected const MAX_SCAN_PAGES = 25;

    /** Normalized titles of existing Issues, loaded once per run — never a per-row table scan. */
    protected ?array $existingIssueTitles = null;

    /**
     * Columns every row query reads — the database's own properties (same
     * names the CSV importer maps); date properties use the expanded
     * `date:<name>:start` column documented in the fetched schema.
     */
    protected const ROW_COLUMNS = 'url, createdTime, "Name", "date:Date of Incident:start" AS date_start, '
        .'"Classification by Tech Risk Incident" AS classification, "Root Cause Classification" AS root_cause';

    public function __construct(private NotionMcpClient $notion) {}

    /**
     * @param  bool  $restoreDeleted  Full sync — also re-import Issues deleted
     *                                from the dashboard (their tombstones).
     * @param  array<int, string>|null  $restorePageIds  Which tombstoned page
     *                                                   IDs a full sync may restore: a list restores only those (the
     *                                                   Sync-now picker's ticked issues), [] restores nothing — a
     *                                                   deliberate pick of none — and null (no picker involved: the
     *                                                   console/scheduler path) restores every dangling tombstone.
     * @return array{status: string, message: string, created: int, skipped: int, preview: ?string}
     */
    public function sync(bool $dryRun = false, bool $restoreDeleted = false, ?array $restorePageIds = null): array
    {
        $targetId = Setting::get(self::WATCH_KEY);

        if (blank($targetId)) {
            return $this->finish('failed', 'No Notion watch target configured — set it on the Notion Settings page.', 0, 0, $dryRun);
        }

        if (! $this->notion->connected()) {
            return $this->finish('failed', 'Notion is not connected — connect it on the Notion Settings page.', 0, 0, $dryRun);
        }

        $fetch = $this->notion->callToolDetailed('notion-fetch', ['id' => $targetId]);

        if ($fetch === null) {
            return $this->finish('failed', 'Notion fetch failed — check the logs.', 0, 0, $dryRun);
        }

        if ($fetch['isError']) {
            return $this->finish('failed', 'Notion fetch failed: '.$fetch['text'], 0, 0, $dryRun, $fetch['text']);
        }

        $collectionUrl = $this->extractCollectionUrl($fetch['text']);

        if ($collectionUrl === null) {
            return $this->finish('failed', 'Watch target is not a Notion database — set the database page ID on the Notion Settings page.', 0, 0, $dryRun, $fetch['text']);
        }

        $created = 0;
        $failed = 0;
        $alreadySynced = 0;
        $duplicates = 0;
        $blanks = 0;
        $unreadable = 0;
        $lines = [];
        // createdTime of the FIRST failed row — the watermark never advances
        // past it, so the row is retried once its data is fixed (everything
        // after it was already imported and is absorbed by page-ID dedupe).
        $firstFailureTime = null;

        // Restore semantics (owner 2026-10-29): tombstones are only revisited
        // by an explicit full sync — the normal sync (and the scheduler) never
        // brings a deleted Issue back. Needs the collection URL, hence after
        // the fetch. In dry-run this reads Notion only and reports what a full
        // sync would restore.
        if ($restoreDeleted) {
            $recovered = $this->recoverDeletedIssues($collectionUrl, $lines, $dryRun, $restorePageIds);
            $created += $recovered['created'];
            $duplicates += $recovered['duplicates'];
            $blanks += $recovered['blanks'];
            $failed += $recovered['failed'];
        }

        $query = $this->notion->callToolDetailed('notion-query-data-sources', $this->rowQueryArguments($collectionUrl, $this->whereSinceWatermark()));

        if ($query === null) {
            return $this->finish('failed', 'Notion data-source query failed — check the logs.', 0, 0, $dryRun);
        }

        if ($query['isError']) {
            return $this->finish('failed', 'Notion data-source query failed: '.$query['text'], 0, 0, $dryRun, $query['text']);
        }

        $rows = $this->extractRows($query['text']);

        if ($rows === null) {
            return $this->finish('failed', 'Could not read rows from the Notion query response — see the preview for the raw shape.', 0, 0, $dryRun, $query['text']);
        }

        // Dry runs must report REAL totals: the engine returns at most 100
        // rows per query, so a bigger database's counts were silently capped
        // (prod 2026-10-30: "99 already synced" for a larger table). Real
        // syncs keep the single-window drain — the watermark picks the rest
        // up over the following runs.
        $truncated = false;

        if ($dryRun) {
            [$rows, $truncated] = $this->paginateWindow($collectionUrl, $rows);
        }

        // The `>=` window always re-fetches rows sharing the watermark's
        // second; ones already handled last run are expected re-reads, not
        // findings — skipped but never counted.
        $watermark = (string) Setting::get(self::WATERMARK_KEY);

        foreach ($rows as $row) {
            $boundary = ((string) ($row['createdTime'] ?? '')) === $watermark;
            $pageId = $this->pageIdFromUrl((string) ($row['url'] ?? ''));

            if ($pageId === null || NotionSyncedPage::where('page_id', $pageId)->exists()) {
                if (! $boundary) {
                    // A page-ID match is the EXPECTED already-synced state —
                    // a count in the message, never a line: a first-run
                    // preview used to be a wall of "Already synced from
                    // Notion" rows hiding the real findings (owner 2026-10-30).
                    // The boundary re-read is an expected re-read, not a skip.
                    if ($pageId === null) {
                        $unreadable++;
                        $lines[] = '- Unreadable page URL: '.($row['Name'] ?? '?');
                    } else {
                        $alreadySynced++;
                    }
                }

                continue;
            }

            // Per-row isolation: one throwing row (e.g. a title longer than
            // incidents.title under MySQL strict mode) must not wedge the
            // unattended cron — report it and keep importing the rest.
            try {
                $imported = $this->importRow($row, $pageId, $dryRun, $lines);
            } catch (\Throwable $e) {
                $failed++;
                $firstFailureTime ??= (string) ($row['createdTime'] ?? '');

                Log::warning('Notion issue sync: row import failed.', [
                    'page_id' => $pageId,
                    'title' => $row['Name'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $lines[] = 'FAILED: '.($row['Name'] ?? $pageId).' — '.$e->getMessage();

                continue;
            }

            if ($imported !== null) {
                $created++;
            } elseif (! $boundary) {
                // importRow returns null only for a blank title or a duplicate.
                blank($row['Name'] ?? null) ? $blanks++ : $duplicates++;
            }
        }

        $label = $dryRun ? 'Would import' : 'Imported';

        if (! $dryRun) {
            $rowsForWatermark = $firstFailureTime === null
                ? $rows
                : array_values(array_filter($rows, fn (array $candidate): bool => (string) ($candidate['createdTime'] ?? '') < $firstFailureTime));
            $this->advanceWatermark($rowsForWatermark);
        }

        // Per-reason breakdown (owner 2026-10-30): one lumped
        // "already synced/duplicate/blank" number read as trouble when
        // almost all of it is the normal already-synced state. Only
        // non-zero segments appear; `skipped` stays the aggregate.
        $skipped = $alreadySynced + $duplicates + $blanks + $unreadable;

        if ($created === 0 && $skipped === 0 && $failed === 0) {
            $message = 'No new issues.';
        } else {
            $segments = array_filter([
                $created > 0 ? "{$label} {$created} issue(s)" : 'No new issues',
                $alreadySynced > 0 ? "{$alreadySynced} already synced" : null,
                $duplicates > 0 ? "{$duplicates} duplicate title(s)" : null,
                $blanks > 0 ? "{$blanks} blank title(s)" : null,
                $unreadable > 0 ? "{$unreadable} unreadable URL(s)" : null,
                $failed > 0 ? "{$failed} failed" : null,
                $truncated ? 'scan stopped at the '.self::MAX_SCAN_PAGES.'-page cap — counts cover the first '.count($rows).' rows' : null,
            ]);

            $message = implode('; ', $segments).'.';
        }

        return $this->finish($failed > 0 ? 'partial' : 'ok', $message, $created, $skipped, $dryRun, implode("\n", $lines));
    }

    /**
     * Map one Notion row to an Issue and (unless dry-run) create it with its
     * synced-page row. Null when the row is blank or a duplicate title —
     * the caller decides whether that counts as skipped. Shared by the main
     * window loop and deleted-issue recovery so the two cannot drift.
     */
    protected function importRow(array $row, string $pageId, bool $dryRun, array &$lines): ?Incident
    {
        $title = $row['Name'] ?? null;

        if (blank($title)) {
            $lines[] = '- Blank title: '.$pageId;

            return null;
        }

        if ($this->titleAlreadyExists((string) $title)) {
            $lines[] = '- Duplicate title (already in the dashboard): '.$title;

            return null;
        }

        $lines[] = '- '.$this->describeRow($row);
        // This row is being imported — later rows with the same title must
        // dedupe against it (the per-row DB lookup used to see it; the set must too).
        $this->existingIssueTitles[\App\Helpers\StringHelper::normalizeForComparison((string) $title)] = true;

        if ($dryRun) {
            return new Incident;
        }

        $incidentDate = $this->parseDate($row['date_start'] ?? null, $row['createdTime'] ?? null);

        // The database's own severity column wins; the root-cause map is
        // the fallback for rows where nobody classified it yet.
        $severity = NotionIssueMapper::mapClassificationToSeverity($row['classification'] ?? null)
            ?? NotionIssueMapper::mapSeverityToCode((string) ($row['root_cause'] ?? 'G'));

        // Atomic pair: a failed synced-page insert (unique page_id — the
        // manual sync racing the cron) must not leave an orphan Incident.
        $incident = DB::transaction(function () use ($title, $incidentDate, $severity, $pageId): Incident {
            $incident = Incident::create([
                'title' => NotionIssueMapper::cleanTitle((string) $title),
                'incident_date' => $incidentDate,
                'entry_date_tech_risk' => $incidentDate->format('Y-m-d'),
                'severity' => $severity,
                'classification' => IncidentClassification::Issue->value,
                // NOT NULL "Area"/"Source" enums — the CSV importer leaves
                // them unset (it only fills incident_type_id); Notion issues
                // are internally tracked tech issues.
                'incident_type' => 'Tech',
                'incident_source' => 'Internal',
                'incident_type_id' => IncidentType::first()?->id,
                'no' => NotionIssueMapper::generateIssueId(),
            ]);

            NotionSyncedPage::create(['page_id' => $pageId, 'incident_id' => $incident->id]);

            return $incident;
        });

        return $incident;
    }

    /**
     * Duplicate check against the once-per-run title set. New imports join
     * the set, so two identical rows inside one run still dedupe (same
     * semantics the per-row lookup had). Per-instance, never static —
     * long-lived FPM workers must not pin a run's set.
     */
    protected function titleAlreadyExists(string $title): bool
    {
        $this->existingIssueTitles ??= NotionIssueMapper::existingNormalizedTitles();

        return isset($this->existingIssueTitles[\App\Helpers\StringHelper::normalizeForComparison($title)]);
    }

    /**
     * One line's human summary: "{title} ({date}, {severity})" — the shared
     * body of the would-import and would-restore preview lines.
     */
    protected function describeRow(array $row): string
    {
        $incidentDate = $this->parseDate($row['date_start'] ?? null, $row['createdTime'] ?? null);

        // The database's own severity column wins; the root-cause map is
        // the fallback for rows where nobody classified it yet.
        $severity = NotionIssueMapper::mapClassificationToSeverity($row['classification'] ?? null)
            ?? NotionIssueMapper::mapSeverityToCode((string) ($row['root_cause'] ?? 'G'));

        return ($row['Name'] ?? '?')." ({$incidentDate->toDateString()}, {$severity})";
    }

    /**
     * The full-sync restore picker's options (Notion Settings "Sync now"):
     * every dangling tombstone a full sync would ACTUALLY restore — its
     * Notion row is live, titled, and not a duplicate of an existing
     * dashboard title, the same filters recoverDeletedIssues applies, so the
     * list the operator picks from and the run agree. Pages whose lookup
     * fails or that are gone from Notion are not offered (the run reports or
     * drops them); a disconnected Notion offers nothing.
     *
     * @return array<string, string> Notion page ID => "{title} ({date}, {severity})"
     */
    public function restorableIssues(): array
    {
        $targetId = Setting::get(self::WATCH_KEY);

        if (blank($targetId) || ! $this->notion->connected()) {
            return [];
        }

        $fetch = $this->notion->callToolDetailed('notion-fetch', ['id' => $targetId]);

        $collectionUrl = ($fetch === null || $fetch['isError']) ? null : $this->extractCollectionUrl($fetch['text']);

        if ($collectionUrl === null) {
            return [];
        }

        $options = [];

        foreach (NotionSyncedPage::query()->whereNull('incident_id')->pluck('page_id') as $pageId) {
            $row = $this->queryPageById($collectionUrl, $pageId);

            if ($row === null || $row === [] || blank($row['Name'] ?? null) || $this->titleAlreadyExists((string) $row['Name'])) {
                continue;
            }

            $options[$pageId] = $this->describeRow($row);
        }

        return $options;
    }

    /**
     * Re-import Issues deleted from the dashboard. Their synced-page rows
     * dangle (incident_id NULL via the FK's nullOnDelete). Each dangling
     * page is fetched from Notion DIRECTLY by page ID — no watermark
     * rewind, so nothing else is re-read and the incremental window is
     * untouched. A page no longer in Notion loses its tombstone (permanent
     * removal = delete the row in Notion); a failed lookup keeps it for the
     * next run. A page whose title already exists skips (restoring would
     * duplicate) and loses its tombstone so it is not retried every run.
     * The tombstone delete + re-import run in ONE transaction — a throwing
     * import rolls the delete back, so the restore promise never dies.
     *
     * Dry-run (the Preview): reads only — nothing is deleted or imported;
     * each restorable page is reported as a would-restore line.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, string>|null  $onlyPageIds  The picker's selection —
     *                                                null revisits every dangling tombstone, a list only those.
     * @return array{created: int, duplicates: int, blanks: int, failed: int}
     */
    protected function recoverDeletedIssues(string $collectionUrl, array &$lines, bool $dryRun, ?array $onlyPageIds = null): array
    {
        $dangling = NotionSyncedPage::query()
            ->whereNull('incident_id')
            ->when($onlyPageIds !== null, fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereIn('page_id', $onlyPageIds))
            ->get();

        $created = 0;
        $duplicates = 0;
        $blanks = 0;
        $failed = 0;

        foreach ($dangling as $page) {
            $row = $this->queryPageById($collectionUrl, $page->page_id);

            if ($row === null) {
                continue;
            }

            if ($dryRun) {
                // Preview-only: report what a full sync would bring back.
                $title = $row['Name'] ?? null;

                if (blank($title)) {
                    $blanks++;

                    continue;
                }

                if ($this->titleAlreadyExists((string) $title)) {
                    $duplicates++;

                    continue;
                }

                $lines[] = '- Would restore (deleted issue): '.$this->describeRow($row);
                $created++;

                continue;
            }

            if ($row === []) {
                Log::info('Notion issue sync: page no longer in Notion, dropping the tombstone.', ['page_id' => $page->page_id]);
                $page->delete();

                continue;
            }

            try {
                $restored = DB::transaction(function () use ($page, $row, &$lines): bool {
                    // Free the page ID first — importRow re-registers it.
                    NotionSyncedPage::where('page_id', $page->page_id)->whereNull('incident_id')->delete();

                    return $this->importRow($row, $page->page_id, false, $lines) !== null;
                });
            } catch (\Throwable $e) {
                $failed++;

                Log::warning('Notion issue sync: deleted-page re-import failed.', [
                    'page_id' => $page->page_id,
                    'error' => $e->getMessage(),
                ]);
                $lines[] = 'FAILED: '.($row['Name'] ?? $page->page_id).' — '.$e->getMessage();

                continue;
            }

            if ($restored) {
                $created++;
            } else {
                // importRow returned null: blank title or duplicate.
                blank($row['Name'] ?? null) ? $blanks++ : $duplicates++;
            }
        }

        return ['created' => $created, 'duplicates' => $duplicates, 'blanks' => $blanks, 'failed' => $failed];
    }

    /**
     * One page's row from the watched database, looked up by page ID.
     * Returns the row, [] when the page no longer exists, or null when the
     * lookup failed (the caller keeps the tombstone and retries next run).
     *
     * @return array<string, mixed>|array<empty, empty>|null
     */
    protected function queryPageById(string $collectionUrl, string $pageId): ?array
    {
        // $pageId is 32-hex (pageIdFromUrl) — no quoting or injection concerns.
        if (! ctype_xdigit($pageId)) {
            return null;
        }

        $query = $this->notion->callToolDetailed('notion-query-data-sources', [
            'data' => [
                'data_source_urls' => [$collectionUrl],
                'query' => sprintf(
                    'SELECT %s FROM "%s" WHERE url LIKE \'%%%s%%\'',
                    self::ROW_COLUMNS,
                    $collectionUrl,
                    $pageId,
                ),
            ],
        ]);

        if ($query === null || $query['isError']) {
            Log::warning('Notion issue sync: deleted-page lookup failed.', [
                'page_id' => $pageId,
                'text' => $query['text'] ?? null,
            ]);

            return null;
        }

        $rows = $this->extractRows((string) $query['text']);

        return $rows === null ? null : ($rows[0] ?? []);
    }

    /**
     * The rows query: column names are this database's properties (same
     * names the CSV importer maps); date properties use the expanded
     * `date:<name>:start` column documented in the fetched schema.
     * Incremental: only rows created at/after the watermark are read —
     * `>=` (not >) so a row sharing the watermark's second is still fetched
     * and page-ID dedupe absorbs the re-read. ASC + LIMIT means a burst of
     * new rows is drained oldest first, the remainder next run (DESC could
     * skip middles).
     */
    protected function rowQueryArguments(string $collectionUrl, string $where): array
    {
        return ['data' => [
            'data_source_urls' => [$collectionUrl],
            'query' => sprintf(
                'SELECT %s FROM "%s" %sORDER BY createdTime ASC LIMIT %d',
                self::ROW_COLUMNS,
                $collectionUrl,
                $where,
                self::SCAN_LIMIT,
            ),
        ]];
    }

    /**
     * Follow a full page of results with moving `createdTime >= tail`
     * queries until the window is exhausted, so dry-run counts are the REAL
     * totals (the engine returns at most 100 rows per query — prod
     * 2026-10-30 showed exactly 100 of a larger database, silently capping
     * the preview). The tail row itself comes back on the next page and is
     * dropped by URL dedupe — `>=` (not >) so same-second ties at the page
     * boundary are never skipped. Returns [rows, truncated] — truncated
     * only when the MAX_SCAN_PAGES runaway guard fired.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    protected function paginateWindow(string $collectionUrl, array $rows): array
    {
        $seenUrls = [];

        foreach ($rows as $row) {
            $seenUrls[(string) ($row['url'] ?? '')] = true;
        }

        $batch = $rows;
        $pages = 1; // the caller's window query was page 1

        while (count($batch) === self::SCAN_LIMIT && $pages < self::MAX_SCAN_PAGES) {
            $tail = (string) (end($batch)['createdTime'] ?? '');

            if ($tail === '') {
                break;
            }

            $query = $this->notion->callToolDetailed('notion-query-data-sources', $this->rowQueryArguments(
                $collectionUrl,
                sprintf("WHERE createdTime >= '%s' ", str_replace("'", "''", $tail)),
            ));

            if ($query === null || $query['isError']) {
                Log::warning('Notion issue sync: dry-run pagination query failed — reporting the rows read so far.', [
                    'tail' => $tail,
                    'text' => $query['text'] ?? null,
                ]);

                break;
            }

            $next = $this->extractRows((string) $query['text']);

            if ($next === null) {
                break;
            }

            $fresh = array_values(array_filter($next, fn (array $row): bool => ! isset($seenUrls[(string) ($row['url'] ?? '')])));

            if ($fresh === []) {
                break; // only re-reads came back — the window is exhausted
            }

            foreach ($fresh as $row) {
                $seenUrls[(string) ($row['url'] ?? '')] = true;
                $rows[] = $row;
            }

            $batch = $next;
            $pages++;
        }

        $truncated = count($batch) === self::SCAN_LIMIT && $pages >= self::MAX_SCAN_PAGES;

        if ($truncated) {
            Log::warning('Notion issue sync: dry-run scan hit the page cap — counts cover the rows read.', ['rows' => count($rows)]);
        }

        return [$rows, $truncated];
    }

    /**
     * WHERE clause for the incremental query — empty on the first run (full
     * backfill), else everything created at/after the last processed row.
     * The watermark is Notion's own createdTime string, passed through
     * unmodified so the comparison format always matches the column's.
     */
    protected function whereSinceWatermark(): string
    {
        // No default argument — Setting::get caches its default via
        // Cache::remember, and a cached '' would mask a later-set value.
        $watermark = (string) Setting::get(self::WATERMARK_KEY);

        if (blank($watermark)) {
            return '';
        }

        return sprintf("WHERE createdTime >= '%s' ", str_replace("'", "''", $watermark));
    }

    /**
     * Move the watermark to the newest row SEEN (created or skipped — both
     * were handled). Called with the rows BEFORE the first failure, so when
     * any row failed the watermark stops there: everything from that row on
     * is re-read next run — page-ID dedupe absorbs what already landed and
     * the failed row retries once its data is fixed.
     */
    protected function advanceWatermark(array $rows): void
    {
        $newest = '';

        foreach ($rows as $row) {
            $createdTime = (string) ($row['createdTime'] ?? '');

            if ($createdTime > $newest) {
                $newest = $createdTime;
            }
        }

        if ($newest !== '') {
            Setting::set(self::WATERMARK_KEY, $newest);
        }
    }

    /**
     * The queryable table name, from the fetched data-source document
     * (`<data-source url="{{collection://uuid}}">` / `"url":"collection://uuid"`).
     */
    protected function extractCollectionUrl(string $text): ?string
    {
        return preg_match('/collection:\/\/[0-9a-f-]{36}/i', $text, $m) ? $m[0] : null;
    }

    /**
     * @return list<array<string, mixed>>|null null when the response is not parseable JSON
     */
    protected function extractRows(string $text): ?array
    {
        $decoded = json_decode($text, true);

        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            return null;
        }

        return array_values(array_filter($decoded['results'], fn ($row) => is_array($row)));
    }

    /** The trailing 32-hex ID of a Notion page URL. */
    protected function pageIdFromUrl(string $url): ?string
    {
        return preg_match('/([a-f0-9]{32})/i', $url, $m) ? $m[1] : null;
    }

    protected function parseDate(mixed $dateStart, mixed $createdTime): Carbon
    {
        foreach ([$dateStart, $createdTime] as $candidate) {
            if (filled($candidate)) {
                try {
                    return Carbon::parse((string) $candidate)->startOfDay();
                } catch (\Throwable) {
                }
            }
        }

        return Carbon::now()->startOfDay();
    }

    protected function finish(string $status, string $message, int $created, int $skipped, bool $dryRun, ?string $preview = null): array
    {
        if (! $dryRun) {
            Setting::set(self::LAST_SYNC_AT_KEY, now()->toIso8601String());
            Setting::set(self::LAST_SYNC_STATUS_KEY, $status.': '.$message);
        }

        if ($status === 'failed') {
            Log::warning('Notion issue sync: '.$message);
        }

        return ['status' => $status, 'message' => $message, 'created' => $created, 'skipped' => $skipped, 'preview' => $preview];
    }
}
