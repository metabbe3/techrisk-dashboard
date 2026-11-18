<?php

declare(strict_types=1);

namespace App\Filament\Statistics;

use App\Enums\Severity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;

/**
 * Footer statistics for the Incidents table.
 *
 * Lifted from ListIncidents::getTableContentFooter() so the resource table()
 * itself can own the stats declaratively via ->contentFooter().
 */
class IncidentStatsFooterData
{
    /**
     * Preferred input: the table's own filtered query
     * (`$livewire->getFilteredTableQuery()` — tab + filters + search), so the
     * footer can never contradict the table it sits under (BUG-022). Without
     * one, falls back to baseQuery() (request-derived date filters only) for
     * callers with no table context — e.g. ExecutiveCalcSheet passes its own
     * query, tests exercise baseQuery() directly.
     *
     * @param  Builder<\App\Models\Incident>  $query
     */
    public function build(?Builder $query = null): array
    {
        $query ??= $this->baseQuery();

        $query = $query->clone();

        $totalCases = $query->count();

        $avgMtbf = round(self::avgMtbf($query), 3);

        // BUG-021: MySQL returns DECIMAL aggregates as strings (mysqlnd has no
        // native decimal type) — cast before round/abs or strict_types fatals.
        return [
            'totalCases' => $totalCases,
            'avgMttrMins' => round((float) ($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr') ?? 0), 2),
            'avgMttrDays' => round(abs((float) ($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '<', 0)->avg('mttr') ?? 0)), 2),
            'avgMtbf' => $avgMtbf,
            'totalPotentialFundLoss' => (float) $query->sum('potential_fund_loss'),
            'totalFundLoss' => (float) $query->sum('fund_loss'),
            'totalRecoveredFund' => (float) $query->sum('recovered_fund'),
        ];
    }

    /**
     * Span-based average MTBF (gaps between consecutive metric rows) — the
     * ONE implementation every dashboard surface calls: the caller's scope
     * (tab/fund-status/date filters) rides on the builder; the shared metric
     * rules (METRIC_ELIGIBLE severities, Outlier rows excluded) apply here.
     * UNROUNDED — callers keep their own display rounding (footer 3, AI 2,
     * tiles raw via number_format), so consolidation never moves a digit.
     *
     * @param  Builder<\App\Models\Incident>  $query
     */
    public static function avgMtbf(Builder $query): float
    {
        $mtbfQuery = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->withoutOutliers();

        $mtbfCount = $mtbfQuery->count();

        if ($mtbfCount < 2) {
            return 0.0;
        }

        $minDate = $mtbfQuery->min('incident_date');
        $maxDate = $mtbfQuery->max('incident_date');

        if (! $minDate || ! $maxDate) {
            return 0.0;
        }

        $totalDays = Carbon::parse($minDate)->startOfDay()
            ->diffInDays(Carbon::parse($maxDate)->startOfDay());

        return $totalDays / ($mtbfCount - 1);
    }

    /**
     * No-table-context fallback: incidents (non-Issue) with user year access,
     * scoped by the request's date filters ONLY — no tab, no other filters
     * (they are unknowable from the request here). The Incidents footer no
     * longer lands here (BUG-022 fix passes the table's filtered query); the
     * parity tests pin this fallback because BUG-018 built it deliberately.
     */
    private function baseQuery(): Builder
    {
        $model = App::make(\App\Models\Incident::class);

        $query = $model::query()
            ->where('classification', '!=', \App\Enums\IncidentClassification::Issue->value)
            ->applyUserYearAccess(auth()->user());

        // BUG-018: the table's QuickPeriodFilter defaults to "This Year", but the
        // footer used to count every year the user can access (admins saw all
        // years - "Total Cases" did not match the table it sits under).
        // Mirror BOTH date filters — quick_period AND the From/Until
        // custom_date_range — stacked exactly like the table's filter chain,
        // so footer == table for every date-filter combination.
        $tableFilters = request()->input('tableFilters', []);
        $tableFilters = is_array($tableFilters) ? $tableFilters : [];

        $query = \App\Filament\Filters\QuickPeriodFilter::applyPeriod($query, $this->activeQuickPeriod($tableFilters));
        $range = $tableFilters['custom_date_range'] ?? null;

        return \App\Filament\Filters\QuickPeriodFilter::applyDateRange($query, is_array($range) ? $range : null);
    }

    /**
     * Read the active quick_period table filter (defaults to 'year',
     * same as QuickPeriodFilter::make()->default('year')).
     *
     * @param  array<string, mixed>  $tableFilters
     */
    private function activeQuickPeriod(array $tableFilters): string
    {
        $value = $tableFilters['quick_period']['value'] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return 'year';
    }
}
