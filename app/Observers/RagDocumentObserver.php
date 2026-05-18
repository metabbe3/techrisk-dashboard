<?php

namespace App\Observers;

use App\Models\Incident;
use App\Services\Ai\RagService;

class RagDocumentObserver
{
    public function __construct(
        private RagService $ragService,
    ) {}

    public function created(Incident $incident): void
    {
        try {
            $this->ragService->indexIncident($incident);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[RAG] Failed to index new incident', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function updated(Incident $incident): void
    {
        try {
            $this->ragService->indexIncident($incident);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[RAG] Failed to re-index updated incident', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
