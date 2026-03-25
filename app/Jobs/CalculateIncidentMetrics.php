<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Models\Label;
use Carbon\Carbon;
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
        private bool $shouldUpdateAdjacent = true
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Reload the incident to ensure we have the latest data
        $this->incident = $this->incident->fresh();

        if ($this->shouldAutoLabel) {
            $this->autoLabel();
        }

        $this->calculateMetrics();
        $this->calculateCategoryMtbf();
        $this->calculateMtbfAll();

        if ($this->shouldUpdateAdjacent) {
            $this->updateAdjacentIncidentMetrics();
        }

        $this->flushIncidentCache();
    }

    /**
     * Calculate MTTR and MTBF for an incident.
     */
    private function calculateMetrics(): void
    {
        $incident = $this->incident;

        // Calculate MTTR
        if ($incident->stop_bleeding_at) {
            if ($incident->shouldCalculateMttrByDays()) {
                $days = abs($incident->incident_date->startOfDay()
                    ->diffInDays($incident->stop_bleeding_at->startOfDay())) + 1;
                $incident->mttr = -$days;
            } else {
                $incident->mttr = $incident->incident_date->diffInMinutes($incident->stop_bleeding_at);
            }
        } else {
            $incident->mttr = null;
        }

        // Calculate MTBF using optimized query
        $year = $incident->incident_date->year;
        $previousIncident = Incident::whereYear('incident_date', $year)
            ->where('classification', $incident->classification)
            ->where('severity', '!=', 'Non Incident')
            ->where(function ($query) use ($incident) {
                $query->where('incident_date', '<', $incident->incident_date)
                    ->orWhere(function ($query) use ($incident) {
                        $query->where('incident_date', '=', $incident->incident_date)
                            ->where('id', '<', $incident->id);
                    });
            })
            ->orderBy('incident_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($previousIncident) {
            $incident->mtbf = abs($incident->incident_date->startOfDay()
                ->diffInDays($previousIncident->incident_date->startOfDay()));
        } else {
            // First incident of the year - calculate from Jan 1st
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $daysSinceYearStart = abs($incident->incident_date->startOfDay()
                ->diffInDays($yearStart));

            // If MTBF from year start is unrealistically large (> 90 days), set to null
            // This will be displayed as "N/A" or 0 in the UI
            if ($daysSinceYearStart > 90) {
                $incident->mtbf = null;
            } else {
                $incident->mtbf = $daysSinceYearStart;
            }
        }

        $incident->saveQuietly();
    }

    /**
     * Update MTBF and MTTR for adjacent incidents.
     */
    private function updateAdjacentIncidentMetrics(): void
    {
        $incident = $this->incident;
        $year = $incident->incident_date->year;

        $nextIncident = Incident::whereYear('incident_date', $year)
            ->where('classification', $incident->classification)
            ->where('severity', '!=', 'Non Incident')
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
            // Update MTBF based on the current incident (which might have changed)
            $nextIncident->mtbf = abs($nextIncident->incident_date->startOfDay()
                ->diffInDays($incident->incident_date->startOfDay()));

            if ($nextIncident->stop_bleeding_at) {
                if ($nextIncident->shouldCalculateMttrByDays()) {
                    $days = abs($nextIncident->incident_date->startOfDay()
                        ->diffInDays($nextIncident->stop_bleeding_at->startOfDay())) + 1;
                    $nextIncident->mttr = -$days;
                } else {
                    $nextIncident->mttr = $nextIncident->incident_date->diffInMinutes($nextIncident->stop_bleeding_at);
                }
            } else {
                $nextIncident->mttr = null;
            }

            $nextIncident->saveQuietly();

            // ALSO update the incident AFTER the next one
            // because the next incident's MTBF changed
            $this->updateNextAdjacentIncident($nextIncident, $year);
        }
    }

    /**
     * Update the incident after the next incident (cascading MTBF update).
     */
    private function updateNextAdjacentIncident(Incident $incident, int $year): void
    {
        $nextAfter = Incident::whereYear('incident_date', $year)
            ->where('classification', $incident->classification)
            ->where('severity', '!=', 'Non Incident')
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

        if ($nextAfter) {
            // Update MTBF based on the corrected incident
            $nextAfter->mtbf = abs($nextAfter->incident_date->startOfDay()
                ->diffInDays($incident->incident_date->startOfDay()));
            $nextAfter->saveQuietly();
        }
    }

    /**
     * Calculate MTBF for all category types.
     */
    private function calculateCategoryMtbf(): void
    {
        $incident = $this->incident;
        $year = $incident->incident_date->year;

        $categories = [
            'completed' => ['incident_status' => 'Completed'],
            'p4' => ['severity' => 'P4'],
            'non_tech' => ['incident_type' => 'Non-tech'],
            'fund_loss' => ['fund_status' => 'Confirmed loss'],
            'non_fund_loss' => ['fund_status' => 'Non fundLoss'],
            'potential_recovery' => ['fund_status' => 'Potential recovery'],
            'non_incident' => ['severity' => 'Non Incident'],
        ];

        foreach ($categories as $key => $condition) {
            $previousIncident = Incident::whereYear('incident_date', $year)
                ->where('classification', $incident->classification)
                ->where($condition)
                ->where(function ($query) use ($incident) {
                    $query->where('incident_date', '<', $incident->incident_date)
                        ->orWhere(function ($query) use ($incident) {
                            $query->where('incident_date', '=', $incident->incident_date)
                                ->where('id', '<', $incident->id);
                        });
                })
                ->orderBy('incident_date', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if ($previousIncident) {
                $incident->{"mtbf_{$key}"} = abs($incident->incident_date->startOfDay()
                    ->diffInDays($previousIncident->incident_date->startOfDay()));
            } else {
                $yearStart = Carbon::create($year, 1, 1)->startOfDay();
                $incident->{"mtbf_{$key}"} = abs($incident->incident_date->startOfDay()
                    ->diffInDays($yearStart));
            }
        }

        // Special handling for 'recovered' category
        $previousRecovered = Incident::whereYear('incident_date', $year)
            ->where('classification', $incident->classification)
            ->where('recovered_fund', '>', 0)
            ->where(function ($query) use ($incident) {
                $query->where('incident_date', '<', $incident->incident_date)
                    ->orWhere(function ($query) use ($incident) {
                        $query->where('incident_date', '=', $incident->incident_date)
                            ->where('id', '<', $incident->id);
                    });
            })
            ->orderBy('incident_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($previousRecovered) {
            $incident->mtbf_recovered = abs($incident->incident_date->startOfDay()
                ->diffInDays($previousRecovered->incident_date->startOfDay()));
        } else {
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $incident->mtbf_recovered = abs($incident->incident_date->startOfDay()
                ->diffInDays($yearStart));
        }

        $incident->saveQuietly();
    }

    /**
     * Calculate MTBF for ALL incidents + issues combined.
     */
    private function calculateMtbfAll(): void
    {
        $incident = $this->incident;
        $year = $incident->incident_date->year;

        $previousRecord = Incident::whereYear('incident_date', $year)
            ->where('severity', '!=', 'Non Incident')
            ->where(function ($query) use ($incident) {
                $query->where('incident_date', '<', $incident->incident_date)
                    ->orWhere(function ($query) use ($incident) {
                        $query->where('incident_date', '=', $incident->incident_date)
                            ->where('id', '<', $incident->id);
                    });
            })
            ->orderBy('incident_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($previousRecord) {
            $incident->mtbf_all = abs($incident->incident_date->startOfDay()
                ->diffInDays($previousRecord->incident_date->startOfDay()));
        } else {
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $incident->mtbf_all = abs($incident->incident_date->startOfDay()
                ->diffInDays($yearStart));
        }

        $incident->saveQuietly();
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
    }
}
