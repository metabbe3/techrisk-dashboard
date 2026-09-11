<?php

namespace App\Services\Metrics;

use App\Enums\Severity;
use App\Models\Incident;
use Carbon\Carbon;

/**
 * Single home for the per-row metric formulas (mttr, mtbf, mtbf_* categories,
 * mtbf_all). Consumed by the CalculateIncidentMetrics job (observer pipeline)
 * and the incidents:recalculate-metrics command — BUG-008's lesson: two
 * copies of one formula drift, even inside the same file.
 *
 * Methods only set attributes; callers own persistence.
 */
class IncidentMetricsCalculator
{
    public const METRIC_COLUMNS = [
        'mttr',
        'mtbf',
        'mtbf_completed',
        'mtbf_recovered',
        'mtbf_p4',
        'mtbf_non_tech',
        'mtbf_fund_loss',
        'mtbf_non_fund_loss',
        'mtbf_potential_recovery',
        'mtbf_fully_recovered',
        'mtbf_non_tech_loss',
        'mtbf_non_incident',
        'mtbf_all',
    ];

    public function computeAll(Incident $incident): void
    {
        $this->computeMttr($incident);
        $this->computeMtbf($incident);
        $this->computeCategoryMtbf($incident);
        $this->computeMtbfAll($incident);
    }

    /**
     * MTTR: minutes (positive) normally, days (negative, +1 to include both
     * days) when fund status puts resolution on a day granularity.
     */
    public function computeMttr(Incident $incident): void
    {
        if (! $incident->stop_bleeding_at) {
            $incident->mttr = null;

            return;
        }

        if ($incident->shouldCalculateMttrByDays()) {
            $days = abs($incident->incident_date->startOfDay()
                ->diffInDays($incident->stop_bleeding_at->startOfDay())) + 1;
            $incident->mttr = -$days;
        } else {
            $incident->mttr = $incident->incident_date->diffInMinutes($incident->stop_bleeding_at);
        }
    }

    /**
     * Base MTBF: days since the previous metric-eligible row of the same
     * classification/year (ties broken by id), or from Jan 1 for the first
     * row of the year (null past 90 days — shown as "N/A").
     */
    public function computeMtbf(Incident $incident): void
    {
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
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $daysSinceYearStart = abs($incident->incident_date->startOfDay()
                ->diffInDays($yearStart));

            if ($daysSinceYearStart > 90) {
                $incident->mtbf = null;
            } else {
                $incident->mtbf = $daysSinceYearStart;
            }
        }
    }

    /**
     * Category MTBF for every mtbf_* column: days since the previous row in
     * the same category (same classification/year), or from Jan 1.
     */
    public function computeCategoryMtbf(Incident $incident): void
    {
        $year = $incident->incident_date->year;

        $categories = [
            'mtbf_completed' => ['incident_status' => 'Completed'],
            'mtbf_p4' => ['severity' => 'P4'],
            'mtbf_non_tech' => ['incident_type' => 'Non-tech'],
            'mtbf_fund_loss' => ['fund_status' => 'Confirmed loss'],
            'mtbf_non_fund_loss' => ['fund_status' => 'Non fundLoss'],
            'mtbf_potential_recovery' => ['fund_status' => 'Potential recovery'],
            'mtbf_fully_recovered' => ['fund_status' => 'Fully recovered'],
            'mtbf_non_tech_loss' => ['fund_status' => 'Non Tech Loss'],
            'mtbf_non_incident' => ['severity' => 'Non Incident'],
        ];

        foreach ($categories as $column => $condition) {
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
                $incident->{$column} = abs($incident->incident_date->startOfDay()
                    ->diffInDays($previousIncident->incident_date->startOfDay()));
            } else {
                $yearStart = Carbon::create($year, 1, 1)->startOfDay();
                $incident->{$column} = abs($incident->incident_date->startOfDay()
                    ->diffInDays($yearStart));
            }
        }

        // Recovered category (recovered_fund > 0)
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
    }

    /**
     * MTBF ignoring classification (Incidents + Issues combined) — used by
     * the Issue menu. Excludes non-metric severities.
     */
    public function computeMtbfAll(Incident $incident): void
    {
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
    }
}
