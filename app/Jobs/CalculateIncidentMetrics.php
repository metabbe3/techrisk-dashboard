<?php

namespace App\Jobs;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
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
        private bool $shouldUpdateAdjacent = true,
        private ?string $previousClassification = null
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

            // If classification changed, also update adjacent incidents in the OLD classification
            // since this incident left their group
            if ($this->previousClassification && $this->previousClassification !== $this->incident->classification->value) {
                $this->updateAdjacentForClassification($this->previousClassification);
            }
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
            ->where('classification', $incident->classification->value)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
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
            // Update base MTBF — days from this incident to next
            $nextIncident->mtbf = abs($nextIncident->incident_date->startOfDay()
                ->diffInDays($incident->incident_date->startOfDay()));

            // Update MTTR
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

            // Recalculate category MTBF for the next incident — its "previous"
            // (this incident) may have changed date, affecting category gaps
            $this->recalculateCategoryMtbfFor($nextIncident);
            $this->recalculateMtbfAllFor($nextIncident);

            $nextIncident->saveQuietly();
        }
    }

    /**
     * Update the next incident in the OLD classification group after a classification change.
     * This incident left that group, so the next incident's MTBF (which was relative to this one)
     * now needs to find a new "previous" incident.
     */
    private function updateAdjacentForClassification(string $oldClassification): void
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
            $this->recalculateCategoryMtbfFor($nextInOldGroup);
            $nextInOldGroup->saveQuietly();
        }
    }

    /**
     * Recalculate all category MTBF columns for a given incident.
     * Used when updating adjacent incidents whose "previous" may have changed.
     */
    private function recalculateCategoryMtbfFor(Incident $target): void
    {
        $year = $target->incident_date->year;

        // Load all incidents for this year + classification once, sorted for MTBF calculation
        $yearIncidents = Incident::whereYear('incident_date', $year)
            ->where('classification', $target->classification->value)
            ->orderBy('incident_date')->orderBy('id')
            ->get(['id', 'incident_date', 'severity', 'fund_status', 'recovered_fund', 'incident_type']);

        // Find target's index in the sorted collection
        $targetIndex = $yearIncidents->search(fn ($inc) => $inc->id === $target->id);

        $categories = [
            'mtbf_ongoing' => fn ($inc) => $inc->incident_status !== IncidentStatus::Completed,
            'mtbf_completed' => fn ($inc) => $inc->incident_status === IncidentStatus::Completed,
            'mtbf_p4' => fn ($inc) => $inc->severity === Severity::P4,
            'mtbf_tech' => fn ($inc) => $inc->incident_type === 'Tech',
            'mtbf_non_tech' => fn ($inc) => $inc->incident_type === 'Non-tech',
            'mtbf_fund_loss' => fn ($inc) => $inc->fund_status === FundStatus::ConfirmedLoss,
            'mtbf_potential_recovery' => fn ($inc) => $inc->fund_status === FundStatus::PotentialRecovery,
            'mtbf_fully_recovered' => fn ($inc) => $inc->fund_status === FundStatus::FullyRecovered,
            'mtbf_non_tech_loss' => fn ($inc) => $inc->fund_status === FundStatus::NonTechLoss,
            'mtbf_non_incident' => fn ($inc) => $inc->severity === Severity::NonIncident,
        ];

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();

        foreach ($categories as $column => $filter) {
            // Get all incidents matching this category, before the target
            $previous = null;
            for ($i = ($targetIndex !== false ? $targetIndex - 1 : $yearIncidents->count() - 1); $i >= 0; $i--) {
                if ($filter($yearIncidents[$i])) {
                    $previous = $yearIncidents[$i];
                    break;
                }
            }

            $target->{$column} = $previous
                ? abs($target->incident_date->startOfDay()->diffInDays($previous->incident_date->startOfDay()))
                : abs($target->incident_date->startOfDay()->diffInDays($yearStart));
        }

        // Recovered category (recovered_fund > 0)
        $previousRecovered = null;
        for ($i = ($targetIndex !== false ? $targetIndex - 1 : $yearIncidents->count() - 1); $i >= 0; $i--) {
            if ($yearIncidents[$i]->recovered_fund > 0) {
                $previousRecovered = $yearIncidents[$i];
                break;
            }
        }
        $target->mtbf_recovered = $previousRecovered
            ? abs($target->incident_date->startOfDay()->diffInDays($previousRecovered->incident_date->startOfDay()))
            : abs($target->incident_date->startOfDay()->diffInDays($yearStart));
    }

    /**
     * Recalculate mtbf_all for a given incident.
     */
    private function recalculateMtbfAllFor(Incident $target): void
    {
        $year = $target->incident_date->year;

        $previous = Incident::whereYear('incident_date', $year)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->where(function ($query) use ($target) {
                $query->where('incident_date', '<', $target->incident_date)
                    ->orWhere(function ($query) use ($target) {
                        $query->where('incident_date', '=', $target->incident_date)
                            ->where('id', '<', $target->id);
                    });
            })
            ->orderBy('incident_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($previous) {
            $target->mtbf_all = abs($target->incident_date->startOfDay()
                ->diffInDays($previous->incident_date->startOfDay()));
        } else {
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $target->mtbf_all = abs($target->incident_date->startOfDay()
                ->diffInDays($yearStart));
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
            'fully_recovered' => ['fund_status' => 'Fully recovered'],
            'non_tech_loss' => ['fund_status' => 'Non Tech Loss'],
            'non_incident' => ['severity' => 'Non Incident'],
        ];

        foreach ($categories as $key => $condition) {
            $previousIncident = Incident::whereYear('incident_date', $year)
                ->where('classification', $incident->classification->value)
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
            ->where('classification', $incident->classification->value)
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
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
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
        Cache::increment('dashboard_cache_version');
    }
}
