<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Services\RecurrenceDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectRecurrenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private Incident $incident
    ) {}

    public function handle(RecurrenceDetectionService $service): void
    {
        $this->incident = $this->incident->fresh();

        if ($this->incident->recurrence_data !== null) {
            return;
        }

        try {
            $service->detect($this->incident);
        } catch (\Throwable $e) {
            Log::warning('[DetectRecurrenceJob] Failed', [
                'incident_id' => $this->incident->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
