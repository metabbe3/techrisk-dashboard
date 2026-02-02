<?php

namespace App\Jobs;

use App\Models\InvestigationDocument;
use App\Services\Markdown\DocumentConverterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ConvertDocumentToMarkdown implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public $timeout = 480; // 8 minutes

    public function __construct(
        public InvestigationDocument $document
    ) {
        $this->onQueue('document-conversion');
    }

    public function handle(DocumentConverterService $converter): void
    {
        try {
            $converter->convert($this->document);
        } catch (\Exception $e) {
            Log::error('Failed to convert document to markdown in queue', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->document->update([
            'markdown_conversion_status' => 'failed',
        ]);

        Log::error('Document to Markdown conversion job failed permanently', [
            'document_id' => $this->document->id,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);
    }
}
