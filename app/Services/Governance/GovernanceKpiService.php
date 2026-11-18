<?php

declare(strict_types=1);

namespace App\Services\Governance;

use App\Enums\DetectedVia;
use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Models\ActionImprovement;
use App\Models\Incident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Governance scorecard KPIs (VP TechOps PDR §10) — single source for
 * GovernanceKpiOverview + DetectionLagChart. All incident counts use the
 * register-wide rule: aiCounts() (Incident classification + METRIC_ELIGIBLE
 * severity + fund-status exclusion), scoped to the period.
 *
 * RAG thresholds follow the PDR table; "Negative MTTR = 0" is replaced by
 * "FL usable timing %" because negative MTTR is the deliberate day-unit
 * encoding (docs/findings/findings.md, 2026-10-13 audit).
 */
class GovernanceKpiService
{
    public function __construct(private readonly ?string $startDate = null, private readonly ?string $endDate = null) {}

    /**
     * Everything the overview widget shows, cached 15 min. Keyed on
     * dashboard_cache_version so a recalc bump refreshes it.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return Cache::remember($this->cacheKey('governance_v2'), now()->addMinutes(15), function () {
            $timing = $this->timingRows();
            $fl = $timing->where('fund_status', FundStatus::ConfirmedLoss->value);
            $nfl = $timing->where('fund_status', FundStatus::NonFundLoss->value);

            return [
                'register_completeness_pct' => $this->registerCompletenessPct(),
                'rca_within_5d' => $this->rcaWithinFiveDays(),
                'overdue_action_items' => $this->overdueActionItemCount(),
                'recurrence_this_quarter' => $this->recurrenceThisQuarterCount(),
                'fl_detection_lag_median_hours' => $this->median($fl->pluck('detection_lag_hours')),
                'fl_usable_timing_pct' => $this->usableTimingPct($fl),
                'nfl_detection_lag_median_hours' => $this->median($nfl->pluck('detection_lag_hours')),
                'nfl_usable_timing_pct' => $this->usableTimingPct($nfl),
                'fl_unknown_channel_pct' => $this->flUnknownChannelPct(),
                'placeholder_time_count' => $timing->where('placeholder_time', true)->count(),
                'period_label' => $this->startDate ? "{$this->startDate} → {$this->endDate}" : now()->year,
            ];
        });
    }

    /**
     * Median detection lag (hours) by quarter and stream, for the chart.
     *
     * @return array{labels: list<string>, fl: list<?float>, nfl: list<?float>}
     */
    public function lagByQuarter(): array
    {
        return Cache::remember($this->cacheKey('governance_lag_q_v2'), now()->addMinutes(15), function () {
            $quarters = $this->timingRows()
                ->groupBy(fn (array $row) => $row['incident_date']->format('Y').'-Q'.$row['incident_date']->quarter)
                ->sortKeys();

            return [
                'labels' => $quarters->keys()->values()->all(),
                'fl' => $quarters->map(fn (Collection $rows) => $this->median(
                    $rows->where('fund_status', FundStatus::ConfirmedLoss->value)->pluck('detection_lag_hours')
                ))->values()->all(),
                'nfl' => $quarters->map(fn (Collection $rows) => $this->median(
                    $rows->where('fund_status', FundStatus::NonFundLoss->value)->pluck('detection_lag_hours')
                ))->values()->all(),
            ];
        });
    }

    /**
     * Completed rows that are fully documented: RCA present (missing_root_cause
     * semantics), stop-bleed time present, at least one action item.
     */
    public function registerCompletenessPct(): ?float
    {
        $completed = $this->scoped()
            ->where('incident_status', IncidentStatus::Completed->value)
            ->withExists('actionImprovements')
            ->get();
        if ($completed->isEmpty()) {
            return null;
        }

        $complete = $completed->filter(function (Incident $incident) {
            $hasRca = filled($incident->root_cause) && $incident->root_cause !== 'N/A';
            $hasRcaCategory = filled($incident->root_cause_category);

            return ($hasRca || $hasRcaCategory)
                && $incident->stop_bleeding_at !== null
                && $incident->action_improvements_exists;
        })->count();

        return round($complete / $completed->count() * 100, 1);
    }

    /**
     * RCA recorded within 5 days of the incident, from the audit trail.
     * Returns pct + coverage (how many Completed-with-RCA rows have audits —
     * pre-audit legacy rows would otherwise silently drag the number down).
     *
     * @return array{pct: ?float, coverage: int, total: int}
     */
    public function rcaWithinFiveDays(): array
    {
        $withRca = $this->scoped()
            ->where('incident_status', IncidentStatus::Completed->value)
            ->whereNotNull('root_cause')
            ->whereNot('root_cause', '')
            ->whereNot('root_cause', 'N/A')
            ->get();

        if ($withRca->isEmpty()) {
            return ['pct' => null, 'coverage' => 0, 'total' => 0];
        }

        $firstRcaAt = DB::table('audits')
            ->where('auditable_type', (new Incident)->getMorphClass())
            ->whereIn('auditable_id', $withRca->pluck('id'))
            ->orderBy('auditable_id')
            ->orderBy('created_at')
            ->get(['auditable_id', 'new_values', 'created_at'])
            ->groupBy('auditable_id')
            ->map(fn (Collection $rows) => $rows->first(fn ($audit) => filled(data_get(json_decode($audit->new_values, true), 'root_cause')))?->created_at)
            ->filter();

        $within = 0;
        foreach ($withRca as $incident) {
            $rcaAt = $firstRcaAt->get($incident->id);
            if ($rcaAt === null) {
                continue;
            }
            if (abs(Carbon::parse($rcaAt)->diffInDays($incident->incident_date)) <= 5) {
                $within++;
            }
        }

        return [
            'pct' => $firstRcaAt->isEmpty() ? null : round($within / $firstRcaAt->count() * 100, 1),
            'coverage' => $firstRcaAt->count(),
            'total' => $withRca->count(),
        ];
    }

    /** All overdue open action items (pending + on_progress — Rule A 2026-11-17). */
    public function overdueActionItemCount(): int
    {
        return ActionImprovement::query()
            ->whereIn('status', ActionImprovement::openStatuses())
            ->whereDate('due_date', '<', today())
            ->count();
    }

    /**
     * PDR KPI: share of Confirmed-loss rows with no or Unknown detection
     * channel (red > 5%). Starts at 100% — the column is new; it drops as
     * rows get channels recorded, which is the point.
     */
    public function flUnknownChannelPct(): ?float
    {
        $fl = $this->scoped()
            ->where('fund_status', FundStatus::ConfirmedLoss->value)
            ->get(['detected_via']);

        if ($fl->isEmpty()) {
            return null;
        }

        $unknown = $fl->filter(fn (Incident $incident) => $incident->detected_via === null
            || $incident->detected_via === DetectedVia::Unknown)->count();

        return round($unknown / $fl->count() * 100, 1);
    }

    /**
     * Recurring incidents booked this quarter. recurrence_data is written
     * saveQuietly() (no cache bump) — summary's 15-min TTL covers it.
     */
    public function recurrenceThisQuarterCount(): int
    {
        [$qStart, $qEnd] = [now()->startOfQuarter(), now()->endOfQuarter()];

        return $this->scoped()
            ->whereBetween('incident_date', [$qStart, $qEnd])
            ->pluck('recurrence_data')
            // pluck() on an array-cast column returns decoded arrays on
            // hydrated models but raw JSON strings from query-builder rows
            ->filter(function ($value) {
                $data = is_array($value) ? $value : json_decode((string) $value, true);

                return (bool) data_get($data, 'is_recurring');
            })
            ->count();
    }

    /**
     * One pluck feeding every timing KPI: detection lag (discovered −
     * occurrence), response lag (stop-bleed − discovered), stream, and the
     * placeholder-time flag. Rows missing discovered_at carry null lags.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function timingRows(): Collection
    {
        return $this->scoped()
            ->get(['incident_date', 'discovered_at', 'stop_bleeding_at', 'fund_status', 'detection_delay_days', 'start_time_known'])
            ->map(function (Incident $incident) {
                return [
                    'incident_date' => $incident->incident_date,
                    'fund_status' => $incident->fund_status?->value,
                    // Real timestamps only when the start time is known
                    // (explicit flag, else not a 00:00/12:00 placeholder);
                    // falls back to the recorded delay estimate (RD-4).
                    'detection_lag_hours' => $incident->effectiveDetectionLagHours(),
                    'response_lag_hours' => $incident->stop_bleeding_at && $incident->discovered_at
                        ? round($incident->discovered_at->diffInMinutes($incident->stop_bleeding_at) / 60, 2)
                        : null,
                    'placeholder_time' => ! $incident->startTimeKnown(),
                ];
            });
    }

    /** Share of stream rows with a non-placeholder, discovered-at timestamp. */
    private function usableTimingPct(Collection $stream): ?float
    {
        if ($stream->isEmpty()) {
            return null;
        }

        $usable = $stream->filter(fn (array $row) => $row['detection_lag_hours'] !== null && ! $row['placeholder_time'])->count();

        return round($usable / $stream->count() * 100, 1);
    }

    private function scoped(): \Illuminate\Database\Eloquent\Builder
    {
        return Incident::query()->aiCounts()->tap(function ($query) {
            if ($this->startDate && $this->endDate) {
                $query->whereBetween('incident_date', [$this->startDate, $this->endDate]);
            } else {
                $query->whereYear('incident_date', now()->year);
            }
        });
    }

    private function median(Collection $values): ?float
    {
        $values = $values->filter(fn ($v) => $v !== null)->sort()->values();
        if ($values->isEmpty()) {
            return null;
        }

        $mid = intdiv($values->count(), 2);

        return $values->count() % 2
            ? (float) $values[$mid]
            : round(($values[$mid - 1] + $values[$mid]) / 2, 2);
    }

    private function cacheKey(string $prefix): string
    {
        return $prefix.'_'.md5(json_encode([
            'start' => $this->startDate,
            'end' => $this->endDate,
            'v' => (int) Cache::get('dashboard_cache_version', 0),
        ]));
    }
}
