<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * GET /api/v1/corpus?path=… — serves one file out of the incident memory
 * corpus (markdown/corpus/) to the DingTalk agent: it reads index.md first,
 * then opens the page it needs (LLM-Wiki read pattern).
 *
 * This endpoint is the only HTTP surface over corpus files, so it does the
 * traversal defense itself: relative paths INSIDE markdown/corpus, markdown
 * only, size-capped. The Go tool re-validates but the server never trusts it.
 */
class CorpusController extends Controller
{
    use ApiResponser;

    /** Reply budget: pages larger than this go out truncated (flag set). */
    private const MAX_CONTENT_CHARS = 100_000;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'path' => [
                'required', 'string', 'max:200',
                // Relative path inside markdown/corpus, markdown files only:
                // first char is a name char, rest are name/slash/dot chars.
                'regex:/^[A-Za-z0-9._-][A-Za-z0-9\/._-]*\.md$/',
            ],
        ], [
            'path.regex' => 'path must be a relative markdown path inside the corpus (e.g. topics/index.md).',
        ]);

        $rel = (string) $request->string('path');

        if (str_contains($rel, '..') || str_contains($rel, '\\')) {
            return $this->errorResponse('Invalid corpus path.', 422);
        }

        $disk = Storage::disk('local');
        $full = 'markdown/corpus/'.$rel;

        if (! $disk->exists($full)) {
            return $this->errorResponse('Corpus page not found — read index.md for what exists.', 404);
        }

        $content = (string) $disk->get($full);
        $truncated = mb_strlen($content) > self::MAX_CONTENT_CHARS;
        if ($truncated) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS);
        }

        return $this->successResponse([
            'path' => $rel,
            'content' => $content,
            'truncated' => $truncated,
            'chars' => mb_strlen($content),
        ], 'Corpus page retrieved.');
    }
}
