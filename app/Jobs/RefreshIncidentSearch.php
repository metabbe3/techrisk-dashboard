<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Incident;
use App\Services\Ai\ChatContextService;
use App\Services\Ai\RagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-index one incident for AI search + drop the chat/dashboard caches.
 * Dispatched by the IncidentPic pivot hooks — pivot writes fire no Incident
 * model event, and attaching N PICs in one save must not re-index N times
 * inline (each indexIncident can be an embedding HTTP call). Unique per
 * incident, so a burst of attaches coalesces into one queue run.
 */
class RefreshIncidentSearch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $incidentId,
    ) {}

    public function handle(RagService $ragService, ChatContextService $chatContext): void
    {
        $incident = Incident::find($this->incidentId);

        if ($incident) {
            $ragService->indexIncident($incident);
        }

        $chatContext->clearDataCache();
    }

    public function uniqueId(): string
    {
        return (string) $this->incidentId;
    }
}
