<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Exports\Sheets\IssuesMetricSheetExport;
use App\Exports\Sheets\SingleIncidentSheetExport;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MultiSheetIncidentsExport implements WithMultipleSheets
{
    protected Builder $query;

    protected array $headings;

    protected array $columnNames;

    public function __construct(Builder $query, array $headings, array $columnNames)
    {
        $this->query = $query;
        $this->headings = $headings;
        $this->columnNames = $columnNames;
    }

    public function sheets(): array
    {
        $sheets = [];

        // Base query for Incidents only (exclude Issues) - sorted by date for correct MTBF/MTTR context
        $incidentsQuery = $this->query->clone()
            ->where('classification', IncidentClassification::Incident->value)
            ->orderBy('incident_date', 'asc');

        // 1. All Cases (Incidents only)
        $sheets[] = new SingleIncidentSheetExport($incidentsQuery->clone(), 'All Cases', $this->headings, $this->columnNames);

        // 2. Completed Cases (Incidents only)
        $completedQuery = $incidentsQuery->clone()->where('incident_status', IncidentStatus::Completed->value);
        $sheets[] = new SingleIncidentSheetExport($completedQuery, 'Completed Cases', $this->headings, $this->columnNames);

        // 3. Recovered Cases (Incidents only)
        $recoveredQuery = $incidentsQuery->clone()->where('recovered_fund', '>', 0);
        $sheets[] = new SingleIncidentSheetExport($recoveredQuery, 'Recovered Cases', $this->headings, $this->columnNames);

        // 4. P4 Incidents (Incidents only)
        $p4Query = $incidentsQuery->clone()->where('severity', Severity::P4->value);
        $sheets[] = new SingleIncidentSheetExport($p4Query, 'P4 Incidents', $this->headings, $this->columnNames);

        // 5. Non-Tech Incidents (Incidents only)
        $nonTechQuery = $incidentsQuery->clone()->where('incident_type', IncidentType::NonTech->value);
        $sheets[] = new SingleIncidentSheetExport($nonTechQuery, 'Non-Tech Incidents', $this->headings, $this->columnNames);

        // 6. Fund Loss (Incidents only)
        $fundLossQuery = $incidentsQuery->clone()->where('fund_status', FundStatus::ConfirmedLoss->value);
        $sheets[] = new SingleIncidentSheetExport($fundLossQuery, 'Fund Loss', $this->headings, $this->columnNames);

        // 7. On Going (Incidents only) - Non-completed incidents
        $onGoingQuery = $incidentsQuery->clone()->where('incident_status', '!=', IncidentStatus::Completed->value);
        $sheets[] = new SingleIncidentSheetExport($onGoingQuery, 'On Going', $this->headings, $this->columnNames);

        // 8. Potential Recovery (Incidents only)
        $potentialRecoveryQuery = $incidentsQuery->clone()->where('fund_status', FundStatus::PotentialRecovery->value);
        $sheets[] = new SingleIncidentSheetExport($potentialRecoveryQuery, 'Potential Recovery', $this->headings, $this->columnNames);

        // 9. Fully Recovered (Incidents only)
        $fullyRecoveredQuery = $incidentsQuery->clone()->where('fund_status', FundStatus::FullyRecovered->value);
        $sheets[] = new SingleIncidentSheetExport($fullyRecoveredQuery, 'Fully Recovered', $this->headings, $this->columnNames);

        // 10. Non Tech Loss (Incidents only)
        $nonTechLossQuery = $incidentsQuery->clone()->where('fund_status', FundStatus::NonTechLoss->value);
        $sheets[] = new SingleIncidentSheetExport($nonTechLossQuery, 'Non Tech Loss', $this->headings, $this->columnNames);

        // 11. Non Fund Loss (Incidents only)
        $nonFundLossQuery = $incidentsQuery->clone()->where('fund_status', FundStatus::NonFundLoss->value);
        $sheets[] = new SingleIncidentSheetExport($nonFundLossQuery, 'Non Fund Loss', $this->headings, $this->columnNames);

        // (No "Non Incident" sheet: exports are METRIC_ELIGIBLE-only, so it
        // would be structurally empty — owner rule 2026-09-30.)

        // Issues tabs - Use fresh query for Issues only (separate from Incidents) - sorted by date
        // Note: These tabs always show ALL Issues (not filtered), because metrics need chronological order
        // 12. All Issues
        $issuesQuery = Incident::where('classification', IncidentClassification::Issue->value)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->orderBy('incident_date', 'asc');
        $sheets[] = new SingleIncidentSheetExport($issuesQuery, 'All Issues', $this->headings, $this->columnNames);

        // 13. Issues - MTTR (Issue Name, Type, MTTR) - Sorted by date ASC for correct MTTR
        $issuesMttrQuery = Incident::where('classification', IncidentClassification::Issue->value)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->whereNotNull('mttr')
            ->where('mttr', '>=', 0) // Only regular incidents (positive minutes)
            ->orderBy('incident_date', 'asc');
        $sheets[] = new IssuesMetricSheetExport($issuesMttrQuery, 'Issues - MTTR', 'mttr');

        // 14. Issues - MTBF (Issue Name, Type, MTBF) - Sorted by date ASC for correct MTBF
        $issuesMtbfQuery = Incident::where('classification', IncidentClassification::Issue->value)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->whereNotNull('mtbf')
            ->orderBy('incident_date', 'asc');
        $sheets[] = new IssuesMetricSheetExport($issuesMtbfQuery, 'Issues - MTBF', 'mtbf');

        return $sheets;
    }
}
