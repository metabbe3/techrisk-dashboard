<?php

namespace App\Jobs;

use App\Enums\Severity;
use App\Models\Incident;
use App\Models\Label;
use App\Services\Metrics\IncidentMetricsCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class CalculateIncidentMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Incident $incident,
        private bool $shouldAutoLabel = false,
        private bool $shouldUpdateAdjacent = true,
        private ?string $previousClassification = null
    ) {}

    /**
     * Execute the job. Formulas live in IncidentMetricsCalculator; this job
     * owns the pipeline — persistence, adjacent-row repair, cache busting.
     */
    public function handle(IncidentMetricsCalculator $calculator): void
    {
        // Reload the incident to ensure we have the latest data
        $this->incident = $this->incident->fresh();

        if ($this->shouldAutoLabel) {
            $this->autoLabel();
        }

        $calculator->computeAll($this->incident);
        $this->incident->saveQuietly();

        if ($this->shouldUpdateAdjacent) {
            $this->updateAdjacentIncidentMetrics($calculator);

            // If classification changed, also update adjacent incidents in the OLD classification
            // since this incident left their group
            if ($this->previousClassification && $this->previousClassification !== $this->incident->classification->value) {
                $this->updateAdjacentForClassification($this->previousClassification, $calculator);
            }
        }

        $this->flushIncidentCache();
    }

    /**
     * Update MTBF and MTTR for the next incident — its "previous" row (this
     * incident) may have changed.
     */
    private function updateAdjacentIncidentMetrics(IncidentMetricsCalculator $calculator): void
    {
        $incident = $this->incident;
        $year = $incident->incident_date->year;

        $nextIncident = Incident::whereYear('incident_date', $year)
            ->where('classification', $incident->classification->value)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->where(function ($query) use ($incident) {
                $query->where('incident_date', '>', $incident->incident_date)
                    ->orWhere(function ($query) use ($incident) {
                        $query->where('incident_date', '=', $incident->incident_date)
                            ->where('id', '>', $incident->id);
                    });
            })
            ->orderBy('incident_date', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($nextIncident) {
            $calculator->computeMtbf($nextIncident);
            $calculator->computeMttr($nextIncident);
            $calculator->computeCategoryMtbf($nextIncident);
            $calculator->computeMtbfAll($nextIncident);

            $nextIncident->saveQuietly();
        }
    }

    /**
     * Update the next incident in the OLD classification group after a classification change.
     * This incident left that group, so the next incident's MTBF (which was relative to this one)
     * now needs to find a new "previous" incident.
     */
    private function updateAdjacentForClassification(string $oldClassification, IncidentMetricsCalculator $calculator): void
    {
        $incident = $this->incident;
        $year = $incident->incident_date->year;

        $nextInOldGroup = Incident::whereYear('incident_date', $year)
            ->where('classification', $oldClassification)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->where(function ($query) use ($incident) {
                $query->where('incident_date', '>', $incident->incident_date)
                    ->orWhere(function ($query) use ($incident) {
                        $query->where('incident_date', '=', $incident->incident_date)
                            ->where('id', '>', $incident->id);
                    });
            })
            ->orderBy('incident_date', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($nextInOldGroup) {
            $calculator->computeMtbf($nextInOldGroup);
            $calculator->computeCategoryMtbf($nextInOldGroup);
            $calculator->computeMtbfAll($nextInOldGroup);

            $nextInOldGroup->saveQuietly();
        }
    }

    /**
     * Auto-label incident based on summary and root cause.
     */
    private function autoLabel(): void
    {
        $incident = $this->incident;

        $allLabels = Cache::remember('labels', 3600, function () {
            return Label::all();
        });

        $textBlock = strtolower($incident->summary.' '.$incident->root_cause);
        $matchedLabelIds = [];

        // Optimization: Pre-compile regex patterns once instead of in the loop
        $patterns = [];
        foreach ($allLabels as $label) {
            $patterns[$label->id] = "/\b".preg_quote(strtolower($label->name), '/')."\b/";
        }

        // Match using pre-compiled patterns
        foreach ($patterns as $labelId => $pattern) {
            if (preg_match($pattern, $textBlock)) {
                $matchedLabelIds[] = $labelId;
            }
        }

        if (! empty($matchedLabelIds)) {
            $incident->labels()->syncWithoutDetaching($matchedLabelIds);
        }
    }

    /**
     * Flush incident cache.
     */
    private function flushIncidentCache(): void
    {
        Cache::forget('incidents.stats');
        Cache::forget('labels');
        Cache::increment('dashboard_cache_version');
    }
}
