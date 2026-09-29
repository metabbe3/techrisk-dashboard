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
     * @param  Builder<\App\Models\Incident>  $query
     */
    public function build(?Builder $query = null): array
    {
        $query ??= $this->baseQuery();

        $query = $query->clone();

        $totalCases = $query->count();

        $mtbfQuery = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE);
        $mtbfCount = $mtbfQuery->count();
        $avgMtbf = 0;
        if ($mtbfCount > 0) {
            $minDate = $mtbfQuery->min('incident_date');
            $maxDate = $mtbfQuery->max('incident_date');

            if ($minDate && $maxDate) {
                $min = Carbon::parse($minDate)->startOfDay();
                $max = Carbon::parse($maxDate)->startOfDay();
                $totalDays = $min->diffInDays($max);
                $avgMtbf = $mtbfCount > 1 ? round($totalDays / ($mtbfCount - 1), 3) : 0;
            }
        }

        return [
            'totalCases' => $totalCases,
            'avgMttrMins' => round($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr') ?? 0, 2),
            'avgMttrDays' => round(abs($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '<', 0)->avg('mttr') ?? 0), 2),
            'avgMtbf' => $avgMtbf,
            'totalPotentialFundLoss' => $query->sum('potential_fund_loss'),
            'totalFundLoss' => $query->sum('fund_loss'),
            'totalRecoveredFund' => $query->sum('recovered_fund'),
        ];
    }

    /**
     * Default query mirrors ListIncidents table scope when no filtered
     * query is supplied: incidents (non-Issue) with user year access.
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
        // Mirror the active quick_period filter so footer == table.
        $period = $this->activeQuickPeriod();

        return match ($period) {
            'week' => $query->whereBetween('incident_date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
            'month' => $query->whereBetween('incident_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]),
            'all' => $query,
            default => $query->whereBetween('incident_date', [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()]),
        };
    }

    /**
     * Read the active quick_period table filter (defaults to 'year',
     * same as QuickPeriodFilter::make()->default('year')).
     */
    private function activeQuickPeriod(): string
    {
        $tableFilters = request()->input('tableFilters', []);

        if (is_array($tableFilters)) {
            $value = $tableFilters['quick_period']['value'] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'year';
    }
}
