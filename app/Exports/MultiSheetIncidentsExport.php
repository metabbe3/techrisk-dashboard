<?php

namespace App\Exports;

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

        // Base query for Incidents only (exclude Issues)
        $incidentsQuery = $this->query->clone()->where('classification', 'Incident');

        // 1. All Cases (Incidents only)
        $sheets[] = new SingleIncidentSheetExport($incidentsQuery->clone(), 'All Cases', $this->headings, $this->columnNames);

        // 2. Completed Cases (Incidents only)
        $completedQuery = $incidentsQuery->clone()->where('incident_status', 'Completed');
        $sheets[] = new SingleIncidentSheetExport($completedQuery, 'Completed Cases', $this->headings, $this->columnNames);

        // 3. Recovered Cases (Incidents only)
        $recoveredQuery = $incidentsQuery->clone()->where('recovered_fund', '>', 0);
        $sheets[] = new SingleIncidentSheetExport($recoveredQuery, 'Recovered Cases', $this->headings, $this->columnNames);

        // 4. P4 Incidents (Incidents only)
        $p4Query = $incidentsQuery->clone()->where('severity', 'P4');
        $sheets[] = new SingleIncidentSheetExport($p4Query, 'P4 Incidents', $this->headings, $this->columnNames);

        // 5. Non-Tech Incidents (Incidents only)
        $nonTechQuery = $incidentsQuery->clone()->where('incident_type', 'Non-tech');
        $sheets[] = new SingleIncidentSheetExport($nonTechQuery, 'Non-Tech Incidents', $this->headings, $this->columnNames);

        // 6. Fund Loss (Incidents only)
        $fundLossQuery = $incidentsQuery->clone()->where('fund_loss', '>', 0);
        $sheets[] = new SingleIncidentSheetExport($fundLossQuery, 'Fund Loss', $this->headings, $this->columnNames);

        // Issues tabs - Use fresh query (Issues only, separate from Incidents)
        // 7. All Issues
        $issuesQuery = Incident::where('classification', 'Issue');
        $sheets[] = new SingleIncidentSheetExport($issuesQuery, 'All Issues', $this->headings, $this->columnNames);

        // 8. Issues - MTTR (Issue Name, Type, MTTR)
        $issuesMttrQuery = Incident::where('classification', 'Issue')
            ->whereNotNull('mttr')
            ->orderBy('mttr', 'desc');
        $sheets[] = new IssuesMetricSheetExport($issuesMttrQuery, 'Issues - MTTR', 'mttr');

        // 9. Issues - MTBF (Issue Name, Type, MTBF)
        $issuesMtbfQuery = Incident::where('classification', 'Issue')
            ->whereNotNull('mtbf')
            ->orderBy('mtbf', 'desc');
        $sheets[] = new IssuesMetricSheetExport($issuesMtbfQuery, 'Issues - MTBF', 'mtbf');

        return $sheets;
    }
}
