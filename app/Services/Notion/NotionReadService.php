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
    public const MAX_CONTENT_CHARS = 48000;

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

        if (! preg_match('/<content>(.*)<\/content>/s', (string) ($payload['text'] ?? ''), $matches)) {
            return null;
        }

        $body = trim($matches[1]);

        return $body === '' ? null : $body;
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

        if (! preg_match('/collection:\/\/[0-9a-f-]{36}/i', (string) $response['text'], $m)) {
            return null;
        }

        // Manual get/put (not Cache::remember) so a failed resolve is never
        // cached as an empty value.
        Cache::put($key, $m[0], now()->addDay());

        return $m[0];
    }
}
