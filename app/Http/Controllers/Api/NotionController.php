<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notion\FetchNotionRequest;
use App\Http\Requests\Api\V1\Notion\QueryNotionRequest;
use App\Http\Requests\Api\V1\Notion\SearchNotionRequest;
use App\Services\Notion\NotionReadService;
use App\Traits\ApiResponser;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @group Notion
 *
 * Read-only access to the Notion workspace connected to the dashboard
 * (search, page fetch, database query). All endpoints require authentication
 * via Bearer token with the `notion` endpoint allowed.
 */
class NotionController extends Controller
{
    use ApiResponser;

    public function __construct(protected NotionReadService $notion) {}

    /**
     * Search Notion
     *
     * Search the connected Notion workspace for pages and databases.
     *
     * @authenticated
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Notion search results.",
     *   "data": { "text": "...", "truncated": false }
     * }
     * @response 503 { "code": 503, "status": "Error", "message": "Notion is not connected. Connect it on the Notion Settings page.", "data": null }
     * @response 502 { "code": 502, "status": "Error", "message": "Notion error: ...", "data": null }
     */
    public function search(SearchNotionRequest $request)
    {
        try {
            $pageSize = $request->validated('page_size');

            $result = $this->notion->search(
                (string) $request->validated('query'),
                $pageSize === null ? null : (int) $pageSize,
            );

            if ($error = $this->notionError($result)) {
                return $error;
            }

            [$text, $truncated] = $this->notion->clamp((string) $result['text']);

            return $this->successResponse(['text' => $text, 'truncated' => $truncated], 'Notion search results.');
        } catch (Exception $e) {
            Log::error('Failed to search Notion.', ['error' => $e->getMessage()]);

            return $this->errorResponse('Failed to search Notion.', 500);
        }
    }

    /**
     * Fetch a Notion page
     *
     * Fetch a page's notion-flavored markdown body by id or URL.
     *
     * @authenticated
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Notion page content.",
     *   "data": { "content": "# Page markdown...", "truncated": false }
     * }
     * @response 503 { "code": 503, "status": "Error", "message": "Notion is not connected. Connect it on the Notion Settings page.", "data": null }
     * @response 502 { "code": 502, "status": "Error", "message": "Notion error: ...", "data": null }
     */
    public function fetch(FetchNotionRequest $request)
    {
        try {
            $result = $this->notion->fetch((string) $request->validated('id'));

            if ($error = $this->notionError($result)) {
                return $error;
            }

            $body = $this->notion->extractPageBody((string) $result['text']);

            if ($body === null) {
                return $this->successResponse(['content' => null, 'truncated' => false], 'Page fetched with empty body.');
            }

            [$content, $truncated] = $this->notion->clamp($body);

            return $this->successResponse(['content' => $content, 'truncated' => $truncated], 'Notion page content.');
        } catch (Exception $e) {
            Log::error('Failed to fetch Notion page.', ['error' => $e->getMessage()]);

            return $this->errorResponse('Failed to fetch Notion page.', 500);
        }
    }

    /**
     * Query a Notion database
     *
     * Run a read-only SQL query against a Notion database (id, URL, or
     * collection:// url). The engine returns at most 100 rows per query.
     *
     * @authenticated
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Notion query rows.",
     *   "data": { "rows": [ { "...": "..." } ], "count": 1 }
     * }
     * @response 503 { "code": 503, "status": "Error", "message": "Notion is not connected. Connect it on the Notion Settings page.", "data": null }
     * @response 502 { "code": 502, "status": "Error", "message": "Notion error: ...", "data": null }
     */
    public function query(QueryNotionRequest $request)
    {
        try {
            $result = $this->notion->query(
                (string) $request->validated('database'),
                (string) $request->validated('query'),
            );

            if ($error = $this->notionError($result)) {
                return $error;
            }

            $rows = $this->notion->extractRows((string) $result['text']);

            if ($rows === null) {
                return $this->errorResponse('Could not parse Notion query response.', 502);
            }

            return $this->successResponse(['rows' => $rows, 'count' => count($rows)], 'Notion query rows.');
        } catch (Exception $e) {
            Log::error('Failed to query Notion database.', ['error' => $e->getMessage()]);

            return $this->errorResponse('Failed to query Notion database.', 500);
        }
    }

    /**
     * Shared tool-call guard: null means Notion is not connected; isError
     * surfaces the upstream tool error text.
     *
     * @param  array{structured: ?array, text: string, isError: bool}|null  $response
     */
    private function notionError(?array $response): ?JsonResponse
    {
        if ($response === null) {
            return $this->errorResponse('Notion is not connected. Connect it on the Notion Settings page.', 503);
        }

        if ($response['isError']) {
            return $this->errorResponse('Notion error: '.Str::limit((string) $response['text'], 400), 502);
        }

        return null;
    }
}
