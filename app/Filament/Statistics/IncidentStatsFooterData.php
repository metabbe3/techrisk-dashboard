<?php

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

        return $model::query()
            ->where('classification', '!=', \App\Enums\IncidentClassification::Issue->value)
            ->applyUserYearAccess(auth()->user());
    }
}
