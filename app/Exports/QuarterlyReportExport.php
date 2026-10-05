<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Exports\Concerns\QuarterRange;
use App\Exports\Sheets\ExecutiveCalcSheet;
use App\Exports\Sheets\QuarterlyCaseSheet;
use App\Exports\Sheets\QuarterlySummarySheet;
use App\Filament\Statistics\IncidentStatsFooterData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Quarterly Report preset — the owner's hand-made quarterly report as a
 * live export. One Summary sheet (P1–P4/X1–X4 counts per business
 * category, root cause category and quarter + Avg MTBF + widget-aligned
 * fund totals) plus 4 case tabs with the All Tabs membership rules.
 * Scope is always the full filtered set — the per-quarter summary lines
 * are the quarter breakdown (owner decision 2026-10-05).
 */
class QuarterlyReportExport implements WithMultipleSheets
{
    public function __construct(protected Builder $query)
    {
        $this->query = $query->clone();
    }

    public function sheets(): array
    {
        $rows = $this->incidentQuery()->get();

        return [
            new QuarterlySummarySheet($this->buildSummaryRows($rows)),
            new QuarterlyCaseSheet($this->incidentQuery(), 'All Cases'),
            new QuarterlyCaseSheet($this->incidentQuery()->where('recovered_fund', '>', 0), 'Recovered Cases'),
            new QuarterlyCaseSheet($this->incidentQuery()->where('fund_status', FundStatus::ConfirmedLoss->value), 'Fund Loss'),
            new QuarterlyCaseSheet($this->incidentQuery()->where('fund_status', FundStatus::NonFundLoss->value), 'Non Fund Loss'),
        ];
    }

    /** Incidents only, labels eager (isOutlier), chronological — MultiSheetIncidentsExport precedent. */
    private function incidentQuery(): Builder
    {
        return $this->query->clone()
            ->where('classification', IncidentClassification::Incident->value)
            ->with('labels')
            ->orderBy('incident_date', 'asc');
    }

    /**
     * Summary rows for QuarterlySummarySheet. Multi-category incidents
     * count in EVERY matching line (Group-By precedent — block totals may
     * exceed the All row by design); outliers stay in counts (owner rule
     * 2026-10-01).
     *
     * @param  Collection<int, \App\Models\Incident>  $rows
     */
    private function buildSummaryRows(Collection $rows): array
    {
        // BUG-022: enum-cast attributes never equal strings on Collections.
        $sevOf = fn ($i) => $i->severity?->value ?? $i->severity;

        $countRow = function (string $label, Collection $members) use ($sevOf): array {
            $counts = array_fill_keys(Severity::METRIC_ELIGIBLE, 0);
            foreach ($members as $i) {
                $sev = $sevOf($i);
                if (array_key_exists($sev, $counts)) {
                    $counts[$sev]++;
                }
            }

            return ['kind' => 'count', 'label' => $label, 'counts' => $counts, 'total' => array_sum($counts)];
        };

        $result = [];
        $result[] = ['kind' => 'section', 'label' => 'Business Category'];
        $categories = $rows->pluck('business_category')->filter()->flatMap(fn ($a) => (array) $a)->unique()->sort()->values();
        foreach ($categories as $category) {
            $result[] = $countRow($category, $rows->filter(fn ($i) => in_array($category, (array) $i->business_category, true)));
        }

        $result[] = ['kind' => 'section', 'label' => 'Root Cause Category'];
        $rootCauses = $rows->pluck('root_cause_category')->filter()->flatMap(fn ($a) => (array) $a)->unique()->sort()->values();
        foreach ($rootCauses as $rootCause) {
            $result[] = $countRow($rootCause, $rows->filter(fn ($i) => in_array($rootCause, (array) $i->root_cause_category, true)));
        }

        $result[] = ['kind' => 'section', 'label' => 'Quarter'];
        $quarters = $rows->map(fn ($i) => $i->incident_date->year.'-Q'.$i->incident_date->quarter)->unique()->sort()->values();
        foreach ($quarters as $quarter) {
            $result[] = $countRow(
                QuarterRange::label($quarter),
                $rows->filter(fn ($i) => $i->incident_date->year.'-Q'.$i->incident_date->quarter === $quarter)
            );
        }

        $result[] = $countRow('All', $rows);

        // Avg MTBF = shared footer rule (span/(n-1), outlier-free); fund
        // totals = the single KPI implementation (ExecutiveCalcSheet).
        $avgMtbf = (float) app(IncidentStatsFooterData::class)->build($this->incidentQuery())['avgMtbf'];
        $kpi = ExecutiveCalcSheet::computeKpi($rows, $avgMtbf);
        $result[] = ['kind' => 'metric', 'label' => 'Avg MTBF (days)', 'value' => $avgMtbf, 'idr' => false];
        $result[] = ['kind' => 'metric', 'label' => 'Potential Fund Loss', 'value' => $kpi['potential'], 'idr' => true];
        $result[] = ['kind' => 'metric', 'label' => 'Actual Fund Loss', 'value' => $kpi['actual'], 'idr' => true];
        $result[] = ['kind' => 'metric', 'label' => 'Recovered Fund', 'value' => $kpi['recovered'], 'idr' => true];

        return $result;
    }
}
