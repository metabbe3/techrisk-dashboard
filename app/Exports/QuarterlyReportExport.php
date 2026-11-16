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
 * sheet — owner 2026-10-05). All Cases + quarter tabs run on the
 * baseline set (widget-aligned: Confirmed loss + Non fundLoss, owner
 * rule 2026-10-23); the dedicated membership tabs (Recovered / Fund
 * Loss / Non Fund Loss) keep every fund status via $dedicatedQuery.
 * MTBF sequences (owner 2026-11-16): Fund Loss / Non Fund Loss tabs run
 * their OWN rows' chain; All Cases, Recovered Cases and the quarter tabs
 * keep the baseline-set sequence (the report reads as one table there).
 */
class QuarterlyReportExport implements WithMultipleSheets
{
    public function __construct(protected Builder $query, protected ?Builder $dedicatedQuery = null)
    {
        $this->query = $query->clone();
        $this->dedicatedQuery = ($dedicatedQuery ?? $query)->clone();
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
                QuarterRange::label($quarter),
                $this->query
            ))
            ->all();

        // Fund tabs sequence their OWN rows (owner 2026-11-16 — the old
        // baseline-scope gaps mixed in other fund statuses' dates).
        $fundLoss = $this->dedicatedIncidentQuery()->where('fund_status', FundStatus::ConfirmedLoss->value);
        $nonFundLoss = $this->dedicatedIncidentQuery()->where('fund_status', FundStatus::NonFundLoss->value);

        return [
            new QuarterlyCaseSheet($this->incidentQuery(), 'All Cases', $this->query),
            new QuarterlyCaseSheet($this->dedicatedIncidentQuery()->where('recovered_fund', '>', 0), 'Recovered Cases', $this->query),
            new QuarterlyCaseSheet($fundLoss, 'Fund Loss', $fundLoss->clone()),
            new QuarterlyCaseSheet($nonFundLoss, 'Non Fund Loss', $nonFundLoss->clone()),
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

    /** Same shape off the dedicated (all-fund-statuses) base. */
    private function dedicatedIncidentQuery(): Builder
    {
        return $this->dedicatedQuery->clone()
            ->where('classification', IncidentClassification::Incident->value)
            ->with('labels')
            ->orderBy('incident_date', 'asc');
    }
}
