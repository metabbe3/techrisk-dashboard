<?php

declare(strict_types=1);

namespace App\Services\Notion;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Start Investigation: one Notion investigation page per incident/issue —
 * independent of the Notion issue sync. The parent's type is detected by
 * fetching it: under a database data source the row is created from the
 * database's own default template (properties only, then apply_template)
 * and its body is fetched back into the portal; under a plain page parent
 * the seeded markdown content is sent at create.
 */
class NotionInvestigationService
{
    /** Setting key: the Notion parent page new investigation pages are created under. */
    public const PARENT_KEY = 'notion_investigations_parent_id';

    /** Attempts to read the body after apply_template — it lands asynchronously. */
    protected const BODY_FETCH_ATTEMPTS = 3;

    public function __construct(private NotionMcpClient $notion, protected int $bodyRetryDelaySeconds = 2) {}

    /**
     * Create the Notion page, link it, and set the status to In progress.
     * The status changes only after the page was created. `$user` is the
     * operator who triggered the start — recorded in the row's PIC property
     * alongside the portal PICs.
     *
     * @return array{status: string, message: string}
     */
    public function start(Incident $incident, ?User $user = null): array
    {
        // Owner 2026-11-04 (round 9): investigation pages are for incidents —
        // issues never start one.
        if ($incident->classification === IncidentClassification::Issue) {
            return ['status' => 'failed', 'message' => 'Only incidents can start an investigation.'];
        }

        if (filled($incident->notion_investigation_page_id)) {
            if (blank($incident->notion_investigation_unlinked_at)) {
                return ['status' => 'failed', 'message' => 'An investigation page already exists for this record — use "Refresh from Notion".'];
            }

            // Owner 2026-11-04 (round 10): a soft-unlinked record CONTINUES
            // its existing page — the page id is kept through unlink, so
            // start re-reads it (relink) instead of creating a duplicate.
            $relink = $this->refresh($incident);

            if ($relink['status'] === 'ok') {
                return [
                    'status' => 'ok',
                    'message' => 'Continued the existing investigation page — the stored copy was re-imported from Notion.',
                    'page_id' => $incident->notion_investigation_page_id,
                    'continued' => true,
                ];
            }

            // The page was deleted in Notion meanwhile (refresh flagged it)
            // — fall through and create a FRESH page; the old one stays in
            // Notion's trash. Any other failure surfaces as-is.
            if (blank($incident->notion_investigation_deleted_at)) {
                return $relink;
            }
        }

        $parentId = Setting::get(self::PARENT_KEY);

        if (blank($parentId)) {
            return ['status' => 'failed', 'message' => 'No investigation parent page configured — set it on the Notion Settings page.'];
        }

        if (! $this->notion->connected()) {
            return ['status' => 'failed', 'message' => 'Notion is not connected — connect it on the Notion Settings page.'];
        }

        // Owner 2026-11-04: "<yyyymmdd> - <title>" (e.g. "20260925 - QRIS
        // Acquirer"), dated from the record's incident (start) date. Most
        // record titles carry their own date prefix — drop the duplicate.
        $title = Str::limit(
            ($incident->incident_date?->format('Ymd') ?? now()->format('Ymd')).' - '.$this->withoutDatePrefix((string) $incident->title),
            100
        );

        $bareParentId = str_starts_with($parentId, 'collection://')
            ? substr($parentId, strlen('collection://'))
            : $parentId;

        // The parent's type is detected by fetching it, not by the stored
        // prefix: a bare data-source ID (pasted without collection://) would
        // otherwise route as page_id and fail object_not_found. A database
        // parent's created pages become its rows, follow its default
        // template, and fill its properties; a plain page parent gets the
        // seeded markdown content.
        $parent = $this->fetchParent($bareParentId);

        if ($parent === null) {
            return ['status' => 'failed', 'message' => 'Could not read the parent page/database from Notion — check its ID on the Notion Settings page and that it is shared with the Notion connection.'];
        }

        $templateId = null;
        $notes = '';

        if ($parent['isDataSource']) {
            $schema = $parent['schema'];

            if ($schema === null || blank($schema['titleProperty'])) {
                return ['status' => 'failed', 'message' => 'Could not read the parent database\'s schema from Notion — check its data source ID on the Notion Settings page.'];
            }

            $built = $this->dataSourceProperties($incident, $schema['schema'], $schema['titleProperty'], $title, $user);
            $page = ['properties' => $built['properties']];
            $templateId = $schema['templateId'];
            $notes = $built['note'];
        } else {
            $page = ['properties' => ['title' => $title], 'content' => $this->seedMarkdown($incident)];
        }

        $dataSourceId = $parent['isDataSource'] ? ($parent['schema']['dataSourceId'] ?? null) : null;

        $response = $this->notion->callToolDetailed('notion-create-pages', [
            'pages' => [$page],
            'parent' => $parent['isDataSource']
                // create-pages resolves a DASHED data_source_id only — the
                // undashed form fails object_not_found (live 2026-11-03),
                // unlike page_id and notion-fetch, which accept either.
                ? ['data_source_id' => $this->dashed($dataSourceId ?? $bareParentId)]
                : ['page_id' => $bareParentId],
        ]);

        if ($response === null) {
            return ['status' => 'failed', 'message' => 'Notion page creation failed — check the logs.'];
        }

        if ($response['isError']) {
            return ['status' => 'failed', 'message' => "Notion page creation failed: {$response['text']}"];
        }

        $pageId = $this->extractCreatedPageId($response, array_filter([
            $bareParentId,
            $dataSourceId,
        ]));

        if ($pageId === null) {
            return ['status' => 'failed', 'message' => "The page may have been created, but its ID could not be read from Notion's response — search Notion for \"{$title}\" and link it manually."];
        }

        if ($parent['isDataSource']) {
            $notes .= $this->applyDefaultTemplate($pageId, $templateId);
            $markdown = $this->fetchPageBody($pageId);
            $syncedAt = $markdown !== null ? now() : null;

            if ($markdown === null) {
                $notes .= ' The page body is still loading in Notion — use "Refresh from Notion" on the record page in a moment.';
            }
        } else {
            $markdown = $page['content'];
            $syncedAt = now();
        }

        $incident->update([
            'notion_investigation_page_id' => $pageId,
            'notion_investigation_markdown' => $markdown,
            'notion_investigation_synced_at' => $syncedAt,
            'incident_status' => IncidentStatus::InProgress->value,
            'notion_investigation_deleted_at' => null,
            'notion_investigation_unlinked_at' => null,
        ]);

        return ['status' => 'ok', 'message' => 'Investigation page created in Notion.'.rtrim($notes), 'page_id' => $pageId];
    }

    /**
     * Unlink so Start Investigation can run again (the Notion page itself is
     * NOT deleted — the operator removes it in Notion). The record status is
     * untouched. Owner 2026-11-04 (round 10): a LIVE page unlinks SOFT — the
     * page id stays so the section survives and "Relink" (or Start
     * Investigation) picks the same page back up; only the stored copy
     * clears. A page already flagged deleted has nothing to relink (Notion's
     * trash is out of reach — live-probed round 9), so its unlink clears the
     * whole link; re-investigation composes exactly this path.
     *
     * @return array{status: string, message: string}
     */
    public function unlink(Incident $incident): array
    {
        if (blank($incident->notion_investigation_page_id)) {
            return ['status' => 'failed', 'message' => 'No investigation page is linked to this record.'];
        }

        $soft = blank($incident->notion_investigation_deleted_at);

        $incident->update(array_merge([
            'notion_investigation_markdown' => null,
            'notion_investigation_synced_at' => null,
            'notion_investigation_deleted_at' => null,
            'notion_investigation_unlinked_at' => $soft ? now() : null,
        ], $soft ? [] : ['notion_investigation_page_id' => null]));

        return $soft
            ? ['status' => 'ok', 'message' => 'Investigation unlinked — the Notion page is kept. Use "Relink investigation" to pick it back up.']
            : ['status' => 'ok', 'message' => 'Investigation link removed — you can start a new investigation.'];
    }

    /**
     * Owner 2026-11-04 (round 9): the linked page was deleted in Notion.
     * The MCP server cannot restore from trash (live-probed — the
     * notion-update-page command list has no restore, and move-pages leaves
     * the page trashed), so re-investigating unlinks and starts a FRESH page;
     * the old one stays in Notion's trash. The link survives any refusal.
     *
     * @return array{status: string, message: string}
     */
    public function reinvestigate(Incident $incident, ?User $user = null): array
    {
        if (blank($incident->notion_investigation_page_id)) {
            return ['status' => 'failed', 'message' => 'No investigation page for this record yet — start one first.'];
        }

        if (blank($incident->notion_investigation_deleted_at)) {
            return ['status' => 'failed', 'message' => 'The linked page is not detected as deleted — use "Unlink investigation" to start over.'];
        }

        if ($incident->classification === IncidentClassification::Issue) {
            return ['status' => 'failed', 'message' => 'Only incidents can start an investigation — use "Unlink investigation" to clear the old page.'];
        }

        $this->unlink($incident);

        $result = $this->start($incident, $user);

        if ($result['status'] !== 'ok') {
            return $result;
        }

        return ['status' => 'ok', 'message' => 'A fresh investigation page was created — the deleted page remains in Notion\'s trash.'];
    }

    /**
     * Re-read the linked page's body from Notion into the portal. A failure
     * leaves the stored copy untouched.
     *
     * @return array{status: string, message: string}
     */
    public function refresh(Incident $incident): array
    {
        $pageId = $incident->notion_investigation_page_id;

        if (blank($pageId)) {
            return ['status' => 'failed', 'message' => 'No investigation page for this record yet — start one first.'];
        }

        if (! $this->notion->connected()) {
            return ['status' => 'failed', 'message' => 'Notion is not connected — connect it on the Notion Settings page.'];
        }

        $response = $this->notion->callToolDetailed('notion-fetch', ['id' => $pageId]);

        if ($response === null) {
            return ['status' => 'failed', 'message' => 'Notion page fetch failed — check the logs.'];
        }

        if ($response['isError']) {
            return ['status' => 'failed', 'message' => "Notion page fetch failed: {$response['text']}"];
        }

        // A page deleted in Notion fetches WITHOUT an error — the envelope
        // carries <page … deleted> and no body. Round 9: flag the record so
        // the view page offers "Re-investigate" instead of the dead buttons.
        $payload = json_decode($response['text'], true);

        if (preg_match('/<page[^>]*\bdeleted\b/', (string) ($payload['text'] ?? ''))) {
            $incident->update(['notion_investigation_deleted_at' => now()]);

            return ['status' => 'failed', 'message' => 'The linked Notion page has been deleted in Notion — use "Re-investigate" to start a fresh investigation.'];
        }

        $body = $this->extractPageBody($response['text']);

        if ($body === null) {
            return ['status' => 'failed', 'message' => 'Could not read the page body from Notion\'s response — the stored copy is unchanged.'];
        }

        $incident->update([
            'notion_investigation_markdown' => $this->inlineAiBlocks($body),
            'notion_investigation_synced_at' => now(),
            // The page came back (restored by hand in Notion) — unflag.
            'notion_investigation_deleted_at' => null,
            // Round 10: refreshing a soft-unlinked record IS the relink.
            'notion_investigation_unlinked_at' => null,
        ]);

        return ['status' => 'ok', 'message' => 'Investigation refreshed from Notion.'];
    }

    public function pageUrl(string $pageId): string
    {
        return 'https://www.notion.so/'.$pageId;
    }

    /** 32-hex → canonical dashed UUID; anything else passes through. */
    protected function dashed(string $id): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return $id;
        }

        return strtolower(
            substr($id, 0, 8).'-'.substr($id, 8, 4).'-'.substr($id, 12, 4).'-'.substr($id, 16, 4).'-'.substr($id, 20)
        );
    }

    /**
     * 195 of the last 300 record titles begin with their own date
     * ("31 October 2026 - …") — the composed page title already carries the
     * date, so the prefix drops. A separator is required so a real title
     * that merely mentions a date stays intact; a title that is ONLY the
     * date keeps it.
     */
    protected function withoutDatePrefix(string $title): string
    {
        $stripped = preg_replace(
            '/^\s*(?:\d{1,2}\s+\w+\s+\d{4}|\d{4}-\d{2}-\d{2}|\w+\s+\d{1,2},?\s+\d{4})\s*[-–—:]\s*/',
            '',
            $title,
        );

        return filled($stripped) ? $stripped : $title;
    }

    /** The metadata block the operator would otherwise retype into Notion. */
    protected function seedMarkdown(Incident $incident): string
    {
        $pics = $incident->pics->pluck('name')->implode(', ') ?: '—';
        $severity = $incident->severity?->value ?? '—';
        $date = $incident->incident_date?->format('Y-m-d') ?? '—';
        $summary = filled($incident->summary) ? $incident->summary : '(no summary recorded)';

        return <<<MARKDOWN
# Investigation – {$incident->no}

- **ID:** {$incident->no}
- **Date:** {$date}
- **Severity:** {$severity}
- **PIC:** {$pics}
- **Status:** In progress

## Summary

{$summary}

## Findings

## Root Cause
MARKDOWN;
    }

    /**
     * The parent's shape from one notion-fetch: a database data source (its
     * response embeds <data-source-state>) or a plain page. The stored parent
     * ID may be bare or collection://-prefixed — the type comes from the
     * response, never from the prefix.
     *
     * Null means the parent could not be read at all (fetch failed or the
     * response is not a known envelope); a detected database always returns
     * a schema key, null inside when its state is unparseable.
     *
     * @return ?array{isDataSource: bool, schema: ?array{schema: array, titleProperty: ?string, templateId: ?string}}
     */
    protected function fetchParent(string $parentId): ?array
    {
        $response = $this->notion->callToolDetailed('notion-fetch', ['id' => $parentId]);

        if ($response === null || $response['isError']) {
            return null;
        }

        $payload = json_decode($response['text'], true);
        $inner = is_array($payload) ? (string) ($payload['text'] ?? '') : '';

        if (! str_contains($inner, '<data-source-state>')) {
            return ['isDataSource' => false, 'schema' => null];
        }

        return ['isDataSource' => true, 'schema' => $this->parseDataSourceState($inner)];
    }

    /**
     * A database data source's schema from its fetched state: the property
     * list (name → type), the title property's name, the id of the
     * database's default page template (created rows follow it), and the
     * data source's own id from the state url (`collection://<uuid>`) — the
     * stored parent may be the database's ID, which create-pages does not
     * take as a data_source_id.
     *
     * @return ?array{schema: array, titleProperty: ?string, templateId: ?string, dataSourceId: ?string}
     */
    protected function parseDataSourceState(string $inner): ?array
    {
        preg_match('/<data-source-state>\s*(\{.*\})\s*<\/data-source-state>/s', $inner, $matches);
        $state = json_decode($matches[1] ?? '', true);

        if (! is_array($state)) {
            return null;
        }

        $titleProperty = null;

        foreach ($state['schema'] ?? [] as $key => $property) {
            if (($property['type'] ?? null) === 'title' && filled($property['name'] ?? $key)) {
                $titleProperty = (string) ($property['name'] ?? $key);

                break;
            }
        }

        // The template id arrives as a page URL (live 2026-11-03:
        // "default_page_template":"https://app.notion.com/p/<32-hex>").
        preg_match('/"default_page_template"\s*:\s*"([^"]*)"/', $inner, $template);
        preg_match('/([a-f0-9]{32})/i', $template[1] ?? '', $templateId);

        $stateUrl = (string) ($state['url'] ?? '');
        $dataSourceId = str_starts_with($stateUrl, 'collection://')
            ? str_replace('-', '', substr($stateUrl, strlen('collection://')))
            : null;

        return [
            'schema' => is_array($state['schema'] ?? null) ? $state['schema'] : [],
            'titleProperty' => $titleProperty,
            'templateId' => $templateId[1] ?? null,
            'dataSourceId' => preg_match('/^[a-f0-9]{32}$/i', (string) $dataSourceId) ? $dataSourceId : null,
        ];
    }

    /**
     * The creation properties for a database row: the title plus the portal
     * fields the database actually has (an intersection by property name —
     * a database without "Incident Date" must not error). Dates go out as
     * `date:{name}:start` in Y-m-d (live-verified 2026-11-03); selects as
     * the plain option name (live-probed 2026-11-04); PIC as a list of
     * Notion user ids resolved by email.
     *
     * Round 13 mapping (owner 2026-11-04): Incident Date = the portal
     * form's "Occurred Time" (`incident_date`), Entry Date = the portal
     * record's CREATED date, Incident Category = the portal severity (the
     * count-excluded G / Non Incident fill Notion's "N" option), Fund Loss
     * = Yes when the record carries a fund-loss linkage — any fund status
     * but "Non fundLoss"/unset.
     *
     * @param  array<string, mixed>  $schema
     * @return array{properties: array<string, mixed>, note: string}
     */
    protected function dataSourceProperties(Incident $incident, array $schema, string $titleProperty, string $title, ?User $user): array
    {
        $properties = [$titleProperty => $title];

        foreach (['Incident Date' => $incident->incident_date, 'Entry Date' => $incident->created_at] as $name => $date) {
            if ($date !== null && $this->schemaType($schema, $name) === 'date') {
                $properties["date:{$name}:start"] = $date->format('Y-m-d');
            }
        }

        $note = '';

        if ($this->schemaType($schema, 'Incident Category') === 'select') {
            $severity = $incident->severity?->value;
            // The database's option for the portal's count-excluded
            // severities (G / Non Incident) is "N".
            $category = in_array($severity, [Severity::G->value, Severity::NonIncident->value], true)
                ? 'N'
                : $severity;

            // A missing option would fail the create — skip it with a note
            // instead (the intersection philosophy).
            if ($category !== null && in_array($category, $this->schemaOptions($schema, 'Incident Category'), true)) {
                $properties['Incident Category'] = $category;
            } elseif ($category !== null) {
                $note .= " The severity {$category} has no option in the Notion database — Incident Category left empty.";
            }
        }

        if ($this->schemaType($schema, 'Fund Loss') === 'select') {
            $properties['Fund Loss'] = $incident->fund_status !== null && $incident->fund_status !== FundStatus::NonFundLoss
                ? 'Yes'
                : 'No';
        }

        if ($this->schemaType($schema, 'PIC') === 'person') {
            $emails = collect([$user?->email, ...$incident->pics->pluck('email')])
                ->filter()
                ->unique(fn (string $email) => strtolower($email))
                ->values();

            $userIds = $emails
                ->map(fn (string $email) => $this->notionUserId($email))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($userIds !== []) {
                $properties['PIC'] = $userIds;
            } elseif ($emails->isNotEmpty()) {
                $note = ' None of the PIC emails matched a Notion member — PIC left empty.';
            }
        }

        return ['properties' => $properties, 'note' => $note];
    }

    /** A schema entry's type by property name (entries are keyed by name). */
    protected function schemaType(array $schema, string $name): ?string
    {
        foreach ($schema as $key => $property) {
            if (is_array($property) && (string) ($property['name'] ?? $key) === $name) {
                return $property['type'] ?? null;
            }
        }

        return null;
    }

    /** A select schema entry's option names (round 13: the category check). */
    protected function schemaOptions(array $schema, string $name): array
    {
        foreach ($schema as $key => $property) {
            if (is_array($property) && (string) ($property['name'] ?? $key) === $name) {
                return array_values(array_map(
                    fn (array $option) => (string) $option['name'],
                    array_filter($property['options'] ?? [], 'is_array'),
                ));
            }
        }

        return [];
    }

    /**
     * The database's default template applied to the created row
     * (live-verified 2026-11-03: API-created rows are blank — the template
     * does not follow automatically — and apply_template lands
     * asynchronously, hence allow_async: false).
     */
    protected function applyDefaultTemplate(string $pageId, ?string $templateId): string
    {
        if (blank($templateId)) {
            return ' The database has no default template — the page body is empty.';
        }

        $response = $this->notion->callToolDetailed('notion-update-page', [
            'page_id' => $pageId,
            'command' => 'apply_template',
            'template_id' => $this->dashed($templateId),
            'allow_async' => false,
        ]);

        if ($response === null || $response['isError']) {
            return ' The default template could not be applied — the page body is empty.';
        }

        return '';
    }

    /**
     * The created row's body, once the template has landed. Retries while
     * the page still reads as blank; null means the operator should use
     * "Refresh from Notion" later.
     */
    protected function fetchPageBody(string $pageId): ?string
    {
        foreach (range(1, self::BODY_FETCH_ATTEMPTS) as $attempt) {
            if ($attempt > 1) {
                sleep($this->bodyRetryDelaySeconds);
            }

            $response = $this->notion->callToolDetailed('notion-fetch', ['id' => $pageId]);

            if ($response === null || $response['isError']) {
                return null;
            }

            $body = $this->extractPageBody($response['text']);

            if ($body !== null) {
                return $this->inlineAiBlocks($body);
            }
        }

        return null;
    }

    /**
     * Owner 2026-11-04 (round 8): the page envelope renders Notion AI blocks
     * as self-closing `<unknown url="…#<block id>" alt="ai_block" />`
     * placeholders — the generated content is NOT inlined (the operator's
     * "refresh didn't import my AI content"). Each placeholder is replaced
     * with the block's own fetched body (live-verified: a generated block
     * fetches as a page envelope; an empty one as <blank-page>). A failed
     * block fetch keeps the placeholder — the next refresh retries it.
     */
    protected function inlineAiBlocks(string $body): string
    {
        return preg_replace_callback(
            '/<unknown\s[^>]*\/>/i',
            function (array $match): string {
                if (! str_contains($match[0], 'alt="ai_block"')) {
                    return $match[0];
                }

                if (! preg_match('/url="[^"#]+#([a-f0-9-]+)"/i', $match[0], $anchor)) {
                    return $match[0];
                }

                $response = $this->notion->callToolDetailed(
                    'notion-fetch',
                    ['id' => str_replace('-', '', $anchor[1])],
                );

                if ($response === null || $response['isError']) {
                    return $match[0];
                }

                // Round 10 ("table content not fully imported"): a
                // not-yet-generated block anchors as <blank-page> — no
                // <content> to extract. Keeping the placeholder preserves
                // the section (the operator runs the block in Notion, then
                // Refresh inlines it); the old `?? ''` deleted it instead.
                return $this->extractPageBody($response['text']) ?? $match[0];
            },
            $body,
        ) ?? $body;
    }

    /**
     * The page body (notion-flavored markdown) out of a notion-fetch
     * response — the tool result is a JSON envelope whose inner text wraps
     * the body in <content> tags (live 2026-11-03).
     */
    protected function extractPageBody(string $responseText): ?string
    {
        $payload = json_decode($responseText, true);

        if (! is_array($payload)) {
            return null;
        }

        if (! preg_match('/<content>(.*)<\/content>/s', (string) ($payload['text'] ?? ''), $matches)) {
            return null;
        }

        $body = trim($matches[1]);

        return $body === '' ? null : $body;
    }

    /**
     * A portal email → Notion user id (person properties take user ids, not
     * emails — live 2026-11-03). Cached: the workspace roster barely moves.
     */
    protected function notionUserId(string $email): ?string
    {
        $key = 'notion_user_id.'.Cache::get('dashboard_cache_version', 0).'.'.md5(strtolower($email));

        $cached = Cache::tags(['incidents'])->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $response = $this->notion->callToolDetailed('notion-get-users', ['query' => $email]);

        if ($response === null || $response['isError']) {
            return null;
        }

        $payload = json_decode($response['text'], true);

        foreach ($payload['results'] ?? [] as $candidate) {
            // Only an exact address match counts — the search also returns
            // names/addresses that merely contain the query.
            if (($candidate['type'] ?? null) === 'person'
                && strcasecmp((string) ($candidate['email'] ?? ''), $email) === 0
                && filled($candidate['id'] ?? null)) {
                Cache::tags(['incidents'])->put($key, (string) $candidate['id'], now()->addHours(6));

                return (string) $candidate['id'];
            }
        }

        return null;
    }

    /**
     * The notion-create-pages response's text carries the new page's ID
     * (live-verified 2026-11-03: structured is null, text is a JSON blob
     * whose page `url` path is the bare 32-hex ID). The parent IDs are
     * excluded: a response may echo the parent (or its data source), and
     * matching one would link the incident to its own parent.
     *
     * @param  array{structured: ?array, text: string, isError: bool}  $response
     * @param  list<string>  $excludeIds
     */
    protected function extractCreatedPageId(array $response, array $excludeIds): ?string
    {
        $excluded = array_map(
            fn (string $id) => str_replace('-', '', strtolower($id)),
            $excludeIds
        );

        foreach ([$response['text'], ...$this->collectStrings($response['structured'])] as $candidate) {
            if (! is_string($candidate) || ! preg_match_all('/([a-f0-9]{32})/i', $candidate, $matches)) {
                continue;
            }

            foreach ($matches[1] as $match) {
                if (! in_array(strtolower($match), $excluded, true)) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string> every string value found at any depth
     */
    protected function collectStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            $strings = array_merge($strings, $this->collectStrings($item));
        }

        return $strings;
    }
}
