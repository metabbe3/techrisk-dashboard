<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Jobs\ConvertDocumentToMarkdown;
use App\Models\InvestigationDocument;
use App\Services\Ai\AiTextService;
use App\Services\Markdown\DocumentConverterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SummarizeDocumentController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService,
        private readonly DocumentConverterService $converterService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_id' => 'required|integer|exists:investigation_documents,id',
            'model' => 'nullable|string',
        ]);

        $document = InvestigationDocument::with('encryptionKey')->find($validated['document_id']);

        if (! $document) {
            return response()->json(['success' => false, 'error' => 'Document not found.'], 404);
        }

        try {
            $markdown = $this->resolveMarkdownContent($document);

            if (blank($markdown)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Could not extract text from this document. The file type may not be supported.',
                ]);
            }

            $result = $this->aiService->summarizeDocument(
                content: $markdown,
                originalFilename: $document->original_filename,
                model: $validated['model'] ?? null,
            );

            if (! $result->success) {
                return response()->json(['success' => false, 'error' => $result->error]);
            }

            $document->update([
                'ai_summary' => $result->text,
                'ai_summary_model' => $result->model,
                'ai_summary_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'summary' => $result->text,
                'model' => $result->model,
            ]);
        } catch (\Throwable $e) {
            Log::error('Document AI summarization failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Summarization failed: ' . $e->getMessage(),
            ]);
        }
    }

    private function resolveMarkdownContent(InvestigationDocument $document): ?string
    {
        // Try existing markdown conversion first
        $markdown = $document->getMarkdownContent();

        if (filled($markdown)) {
            return $markdown;
        }

        // Attempt conversion if not done yet
        if ($document->markdown_conversion_status !== 'completed') {
            try {
                $markdown = $this->converterService->convert($document);
            } catch (\Throwable $e) {
                Log::warning('Document conversion failed during AI summarize', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $markdown;
    }
}
