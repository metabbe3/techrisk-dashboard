<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Models\Incident;
use App\Services\Ai\SimilarIncidentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto-detect similar incidents for a freshly created incident (owner
 * 2026-10-01 — "every new incident in incident detail have same incident
 * with score"). Runs the same pipeline + persist semantics as the on-demand
 * button; re-detection stays manual.
 */
class DetectSimilarIncidentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** THINK 45s + VERIFY 60s + double-check batches leave headroom. */
    public int $timeout = 300;

    public function __construct(private readonly Incident $incident) {}

    public function handle(SimilarIncidentService $service): void
    {
        $incident = $this->incident->fresh();

        if (! $incident) {
            return;
        }

        if (! $service->isAvailable()) {
            Log::info('[DetectSimilarIncidentsJob] Skipped — AI unavailable', ['incident_id' => $incident->id]);

            return;
        }

        $result = $service->analyze($incident);

        if (! $result->success) {
            Log::warning('[DetectSimilarIncidentsJob] Pipeline failed', [
                'incident_id' => $incident->id,
                'error' => $result->error,
            ]);

            return;
        }

        $service->persist($incident, $result->matches);

        Log::info('[DetectSimilarIncidentsJob] Complete', [
            'incident_id' => $incident->id,
            'matches' => count($result->matches),
        ]);
    }
}
