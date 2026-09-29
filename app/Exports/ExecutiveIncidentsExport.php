<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Sheets\ExecutiveCalcSheet;
use App\Exports\Sheets\ExecutiveDataSheet;
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
        $this->query = $query->clone()->orderBy('incident_date', 'asc');
    }

    public function sheets(): array
    {
        $dataSheet = new ExecutiveDataSheet($this->query);
        $calcSheet = new ExecutiveCalcSheet($this->query);
        $summarySheet = new ExecutiveSummarySheet($this->query, $calcSheet);

        return [
            'Executive Summary' => $summarySheet,
            'Data' => $dataSheet,
            'Calc' => $calcSheet,
        ];
    }
}
