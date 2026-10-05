<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Exports\Concerns\QuarterRange;
use App\Exports\Sheets\QuarterlyCaseSheet;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Quarterly Report preset — the owner's hand-made quarterly report as a
 * live export. 4 case tabs with the All Tabs membership rules plus one
 * tab per quarter holding only that quarter's incidents (no Summary
 * sheet — owner 2026-10-05). Scope is always the full filtered set; the
 * quarter tabs are the breakdown.
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

        // Per-quarter data tabs (owner request 2026-10-05): each holds only
        // that quarter's incidents, same columns as the case tabs. Quarters
        // filter by date range (QuarterRange), never where('quarter').
        $quarterSheets = $rows->map(fn ($i) => $i->incident_date->year.'-Q'.$i->incident_date->quarter)
            ->unique()->sort()->values()
            ->map(fn (string $quarter) => new QuarterlyCaseSheet(
                $this->incidentQuery()->whereBetween('incident_date', QuarterRange::dates($quarter)),
                QuarterRange::label($quarter)
            ))
            ->all();

        return [
            new QuarterlyCaseSheet($this->incidentQuery(), 'All Cases'),
            new QuarterlyCaseSheet($this->incidentQuery()->where('recovered_fund', '>', 0), 'Recovered Cases'),
            new QuarterlyCaseSheet($this->incidentQuery()->where('fund_status', FundStatus::ConfirmedLoss->value), 'Fund Loss'),
            new QuarterlyCaseSheet($this->incidentQuery()->where('fund_status', FundStatus::NonFundLoss->value), 'Non Fund Loss'),
            ...$quarterSheets,
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
}
