<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\QuarterRange;
use App\Exports\Sheets\ExecutiveCalcSheet;
use App\Exports\Sheets\ExecutiveDataSheet;
use App\Exports\Sheets\ExecutiveQuarterSheet;
use App\Exports\Sheets\ExecutiveSummarySheet;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Executive Report export: Data sheet + Executive Summary sheet with KPI
 * cards and 4 native Excel charts (monthly incidents, severity mix, MTTR
 * trend, potential-vs-recovered funds).
 *
 * Scope mirrors the table query it is exported from (active year for the
 * current user, filtered set) — same rows the operator sees.
 *
 * Chart value caches are injected post-write by ChartCacheInjector
 * (wired in ListIncidents) — the single mechanism; see BUG-013.
 */
class ExecutiveIncidentsExport implements WithMultipleSheets
{
    protected Builder $query;

    public function __construct(Builder $query)
    {
        // labels eager-loaded: every sheet's isOutlier() cell rule needs it
        $this->query = $query->clone()->with('labels')->orderBy('incident_date', 'asc');
    }

    public function sheets(): array
    {
        $dataSheet = new ExecutiveDataSheet($this->query);
        $calcSheet = new ExecutiveCalcSheet($this->query);
        $summarySheet = new ExecutiveSummarySheet($this->query, $calcSheet);

        // Per-quarter KPI tabs (owner addendum 2026-09-30): "YYYY-Qn" string
        // sort = chronological; derived from actual rows, so an empty quarter
        // never gets a tab.
        $quarters = $this->query->get('incident_date')
            ->filter(fn ($i) => $i->incident_date !== null)
            ->map(fn ($i) => $i->incident_date->year.'-Q'.$i->incident_date->quarter)
            ->unique()->sort()->values();
        $quarterSheets = [];
        foreach ($quarters as $quarter) {
            $quarterSheets[QuarterRange::label($quarter)] = new ExecutiveQuarterSheet($this->query, $quarter);
        }

        return [
            'Executive Summary' => $summarySheet,
            ...$quarterSheets,
            'Data' => $dataSheet,
            'Calc' => $calcSheet,
        ];
    }
}
