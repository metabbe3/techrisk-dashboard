<?php

declare(strict_types=1);

namespace App\Services\Notion;

use Illuminate\Support\Facades\Cache;

/**
 * Structurally read-only Notion access for the agent-facing v1 API: wraps
 * NotionMcpClient::callToolDetailed for exactly notion-search / notion-fetch
 * / notion-query-data-sources. The DingTalk agent consumes it through
 * Api/NotionController — the workspace OAuth stays server-side. No write
 * verb exists on this class.
 */
class NotionReadService
{
    /**
     * 2× the original 48k (2026-11-17, "read all content"): a full Notion
     * page must not silently push the user's paste/upload out of the
     * combined extract source. The Go loop's own context prune (100k) stays
     * the binding constraint.
     */
    public const MAX_CONTENT_CHARS = 96000;

    /** Extra notion-fetches one readFullPage may spend (children, DBs, subtrees). */
    private const EXTRA_FETCH_CAP = 12;

    public function __construct(protected NotionMcpClient $notion) {}

    /**
     * The hosted notion-search documents only `query` — page_size is sent
     * solely when the caller asked for it, so the server never sees an
     * unknown property.
     *
     * @return array{structured: ?array, text: string, isError: bool}|null
     */
    public function search(string $query, ?int $pageSize = null): ?array
    {
        $args = ['query' => $query];

        if ($pageSize !== null) {
            $args['page_size'] = $pageSize;
        }

        return $this->notion->callToolDetailed('notion-search', $args);
    }

    /**
     * @return array{structured: ?array, text: string, isError: bool}|null
     */
    public function fetch(string $id): ?array
    {
        return $this->notion->callToolDetailed('notion-fetch', ['id' => $id]);
    }

    /**
     * Query a Notion database. $database is a collection:// url (used
     * directly) or a database id/URL resolved to its collection:// url once,
     * then cached for a day.
     *
     * @return array{structured: ?array, text: string, isError: bool}|null
     */
    public function query(string $database, string $sql): ?array
    {
        $url = str_starts_with($database, 'collection://')
            ? $database
            : $this->collectionUrl($database);

        if ($url === null) {
            return ['structured' => null, 'text' => 'Could not resolve the database to a collection url — notion-fetch the database id once, then retry.', 'isError' => true];
        }

        // Argument shape pinned by NotionIssueSyncService::rowQueryArguments.
        return $this->notion->callToolDetailed('notion-query-data-sources', ['data' => [
            'data_source_urls' => [$url],
            'query' => $sql,
        ]]);
    }

    /**
     * The page body (notion-flavored markdown) out of a notion-fetch
     * response — the tool result is a JSON envelope whose inner text wraps
     * the body in <content> tags (contract live-pinned by PROJ-018).
     * Duplicated from NotionInvestigationService rather than refactoring
     * live-verified PROJ-018 code.
     */
    public function extractPageBody(string $responseText): ?string
    {
        $payload = json_decode($responseText, true);

        if (! is_array($payload)) {
            return null;
        }

        $text = (string) ($payload['text'] ?? '');

        if (! preg_match('/<content>(.*)<\/content>/s', $text, $matches)
            && ! preg_match('/<content>(.*)$/s', $text, $matches)) {
            return null;
        }

        $body = trim($matches[1]);

        return $body === '' ? null : $body;
    }

    /**
     * Everything one page has to offer, composed for the intake's extractor
     * (2026-11-17 "read all content"): title + <properties> (AI summary,
     * dates, PIC, Category, Fund Loss), the AI-block-inlined body, child
     * pages one level deep, inline database rows, truncated-subtree drains
     * and image/file blocks compressed to `[image: name]` notes.
     *
     * @param  string  $pageId  bare/dashed page id or a Notion URL
     * @return array{body: ?string, images: int, truncated: bool, error: string}
     */
    public function readFullPage(string $pageId): array
    {
        $fetched = $this->fetch($pageId);

        if ($fetched === null || $fetched['isError']) {
            return ['body' => null, 'images' => 0, 'truncated' => false,
                'error' => trim((string) ($fetched['text'] ?? 'connection failed'))];
        }

        $extraFetches = 0;
        $payload = json_decode($fetched['text'], true);
        $envelope = (string) ($payload['text'] ?? '');

        $head = '';
        if (is_string($payload['title'] ?? null) && trim($payload['title']) !== '') {
            $head .= '# '.trim($payload['title'])."\n\n";
        }

        if (preg_match('/<properties>(.*?)<\/properties>/s', $envelope, $props) && trim($props[1]) !== '') {
            $head .= "## Notion properties (database-row fields + Notion's own AI summary)\n".trim($props[1])."\n\n";
        }

        $body = $this->extractPageBody($fetched['text']);

        if ($body === null) {
            // A blank page (<blank-page>, no <content>) still carries the
            // title/properties head — readable is readable.
            return ['body' => trim($head) !== '' ? trim($head) : null, 'images' => 0,
                'truncated' => false, 'error' => 'no content block in the envelope'];
        }

        // Oversized pages list their omitted subtrees — drain them (same
        // shape as NotionIssueSyncService's live-2026-11-03 drain).
        // ponytail: the flag stays true for any Notion-truncated page even
        // when every subtree drained (owner test pins it, NotionReadServiceFullPageTest);
        // revisit only if the intake's note reads wrong after live use.
        $truncated = ! empty($fetched['structured']['truncated']);

        $blockIds = $this->unknownBlockIds($fetched);
        foreach ($blockIds as $i => $blockId) {
            if ($i >= self::EXTRA_FETCH_CAP) {
                break;
            }

            usleep(300000);

            $subtree = $this->fetch($blockId);

            if ($subtree !== null && ! $subtree['isError'] && ($sub = $this->extractPageBody($subtree['text'])) !== null) {
                $body .= "\n\n".$sub;
            }
        }

        // Notion AI blocks (RCA/MOM/…) anchor as placeholders — inline the
        // generated content, sharing the fetch budget (an uncapped loop
        // fired one MCP call per block, no throttle).
        $body = $this->inlineAiBlocks($body, $extraFetches);

        // Child pages: one level, max 5 — their OWN counter, or a page with
        // several drained subtrees got zero children (the shared counter was
        // already spent). ponytail: the inner tag shape has no repo fixture
        // (live MCP pages only); a shape this regex doesn't recognize passes
        // through raw, so the worst case is "not inlined".
        $childFetches = 0;
        $body = preg_replace_callback(
            '/<page\s[^>]*url="([^"]+)"[^>]*\/?>/i',
            function (array $match) use (&$childFetches): string {
                if (++$childFetches > 5) {
                    return $match[0];
                }

                usleep(300000);

                $childId = self::bareId($match[1]);

                if ($childId === null) {
                    return $match[0];
                }

                $child = $this->fetch($childId);

                if ($child === null || $child['isError']) {
                    return $match[0];
                }

                return "\n\n--- (child page: {$match[1]}) ---\n"
                    .($this->extractPageBody($child['text']) ?? $match[0]);
            },
            $body,
        ) ?? $body;

        // Inline databases: rows read through the existing query verb.
        $body = preg_replace_callback(
            '/<(?:database|data-source)\s[^>]*url="(collection:\/\/[^"]+)"[^>]*\/?>/i',
            function (array $match) use (&$extraFetches): string {
                if (++$extraFetches > self::EXTRA_FETCH_CAP) {
                    return $match[0];
                }

                usleep(300000);

                $rows = $this->query($match[1], 'SELECT * FROM "'.str_replace('"', '', $match[1]).'" LIMIT 50');

                if ($rows === null || $rows['isError'] || ($list = $this->extractRows($rows['text'])) === null) {
                    return $match[0];
                }

                return $match[0]."\n\n## Inline database rows\n".json_encode($list, JSON_UNESCAPED_UNICODE);
            },
            $body,
        ) ?? $body;

        // Images/files are MCP-internal pseudo-URLs with no bytes behind
        // them (no REST token) — compress to a named note so the model
        // knows an image exists and the intake can ask for screenshots.
        $images = 0;
        $body = preg_replace_callback(
            '/<(image|file)\s[^>]*>/i',
            function (array $match) use (&$images): string {
                $name = 'unnamed';

                if (preg_match('/name=([^&"\\s>]+)/', $match[0], $n)) {
                    $name = urldecode($n[1]);
                }

                if (strtolower($match[1]) === 'image') {
                    $images++;
                }

                return '['.$match[1].': '.$name.']';
            },
            $body,
        ) ?? $body;

        $composed = $head.$body;
        $composed = preg_replace('/<colgroup>.*?<\/colgroup>/s', '', $composed) ?? $composed;
        [$composed, $clamped] = $this->clamp($composed);

        $truncated = $truncated || $clamped;

        return ['body' => $composed, 'images' => $images, 'truncated' => $truncated, 'error' => ''];
    }

    /**
     * Inline Notion AI-block placeholders with their generated content.
     * A failed or not-yet-generated block keeps its placeholder. $fetches
     * (optional, by reference) shares a caller's fetch budget — standalone
     * callers get their own counter, always capped: one MCP call per block
     * with no cap could hammer Notion's ~3 req/s limit on AI-heavy pages.
     */
    public function inlineAiBlocks(string $body, ?int &$fetches = null): string
    {
        $ownCounter = $fetches === null;
        $local = 0;

        return preg_replace_callback(
            '/<unknown\s[^>]*\/>/i',
            function (array $match) use (&$fetches, &$local, $ownCounter): string {
                if (! str_contains($match[0], 'alt="ai_block"')
                    || ! preg_match('/url="[^"#]+#([a-f0-9-]+)"/i', $match[0], $anchor)) {
                    return $match[0];
                }

                if ($ownCounter) {
                    if (++$local > self::EXTRA_FETCH_CAP) {
                        return $match[0];
                    }
                } elseif (++$fetches > self::EXTRA_FETCH_CAP) {
                    return $match[0];
                }

                usleep(300000);

                $response = $this->fetch(str_replace('-', '', $anchor[1]));

                if ($response === null || $response['isError']) {
                    return $match[0];
                }

                return $this->extractPageBody($response['text']) ?? $match[0];
            },
            $body,
        ) ?? $body;
    }

    /**
     * The truncated response's omitted subtree roots (Notion MCP docs: pass
     * one back to notion-fetch to retrieve that subtree directly). Mirrors
     * NotionIssueSyncService::unknownBlockIds (kept there live-verified).
     *
     * @param  array{structured: ?array, text: string, isError: bool}  $fetch
     * @return list<string>
     */
    public function unknownBlockIds(array $fetch): array
    {
        $ids = $fetch['structured']['unknown_block_ids'] ?? [];

        return array_values(array_filter(is_array($ids) ? $ids : [], 'is_string'));
    }

    /**
     * The page id in a Notion link: the LAST 32-hex (or dashed UUID) in the
     * URL path, hex-bounded. Never strip dashes first — that glues slug
     * letters onto the id ("RootCaus[e]-1022…", "v[1-0]-d352…") and sends a
     * shifted, nonexistent id (BUG-034: the first-match parser sent it).
     * ponytail: peek links (?p=<id>) resolve to their database; paste the page's own link.
     */
    public static function bareId(string $url): ?string
    {
        preg_match_all(
            '/(?<![0-9a-f])(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?![0-9a-f])/i',
            (string) strtok(trim($url), '?#'),
            $m,
        );

        return $m[0] === [] ? null : end($m[0]);
    }

    /**
     * @return list<array<string, mixed>>|null null when the response is not parseable JSON
     */
    public function extractRows(string $text): ?array
    {
        $decoded = json_decode($text, true);

        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            return null;
        }

        return array_values(array_filter($decoded['results'], fn ($row) => is_array($row)));
    }

    /**
     * Cap a payload for the API consumer. No ellipsis is appended — the
     * `truncated` flag tells the client the doc continues.
     *
     * @return array{0: string, 1: bool} [clamped text, truncated]
     */
    public function clamp(string $text, int $max = self::MAX_CONTENT_CHARS): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text, false];
        }

        return [mb_substr($text, 0, $max), true];
    }

    /**
     * The queryable collection url for a database id/URL, scraped from a
     * notion-fetch of the database (`<data-source url="collection://uuid">`).
     */
    /** The collection:// url inside a fetched database document. */
    public static function collectionUrlFrom(string $text): ?string
    {
        return preg_match('/collection:\/\/[0-9a-f-]{36}/i', $text, $m) ? $m[0] : null;
    }

    protected function collectionUrl(string $database): ?string
    {
        $key = 'notion.collection_url.'.md5($database);

        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->notion->callToolDetailed('notion-fetch', ['id' => $database]);

        if ($response === null || $response['isError']) {
            return null;
        }

        // Manual get/put (not Cache::remember) so a failed resolve is never
        // cached as an empty value.
        $url = self::collectionUrlFrom((string) $response['text']);

        if ($url === null) {
            return null;
        }

        Cache::put($key, $url, now()->addDay());

        return $url;
    }
}
