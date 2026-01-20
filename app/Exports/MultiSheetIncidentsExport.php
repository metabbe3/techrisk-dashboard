<?php

namespace App\Exports;

use App\Exports\Sheets\SingleIncidentSheetExport;
use App\Exports\Sheets\IssuesMetricSheetExport;
use App\Models\Incident;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * @return array
     */
    public function sheets(): array
    {
        $sheets = [];

        // 1. All Cases
        $sheets[] = new SingleIncidentSheetExport($this->query->clone(), 'All Cases', $this->headings, $this->columnNames);

        // 2. Completed Cases
        $completedQuery = $this->query->clone()->where('incident_status', 'Completed');
        $sheets[] = new SingleIncidentSheetExport($completedQuery, 'Completed Cases', $this->headings, $this->columnNames);

        // 3. Recovered Cases
        $recoveredQuery = $this->query->clone()->where('recovered_fund', '>', 0);
        $sheets[] = new SingleIncidentSheetExport($recoveredQuery, 'Recovered Cases', $this->headings, $this->columnNames);

        // 4. P4 Incidents
        $p4Query = $this->query->clone()->where('severity', 'P4');
        $sheets[] = new SingleIncidentSheetExport($p4Query, 'P4 Incidents', $this->headings, $this->columnNames);

        // 5. Non-Tech Incidents
        $nonTechQuery = $this->query->clone()->where('incident_type', 'Non-tech');
        $sheets[] = new SingleIncidentSheetExport($nonTechQuery, 'Non-Tech Incidents', $this->headings, $this->columnNames);

        // 6. Fund Loss
        $fundLossQuery = $this->query->clone()->where('fund_loss', '>', 0);
        $sheets[] = new SingleIncidentSheetExport($fundLossQuery, 'Fund Loss', $this->headings, $this->columnNames);

        // NEW: Issues tabs
        // 7. All Issues
        $issuesQuery = $this->query->clone()->where('classification', 'Issue');
        $sheets[] = new SingleIncidentSheetExport($issuesQuery, 'All Issues', $this->headings, $this->columnNames);

        // 8. Issues - MTTR (simplified: Issue Name, Type, MTTR)
        $issuesMttrQuery = $this->query->clone()
            ->where('classification', 'Issue')
            ->whereNotNull('mttr')
            ->orderBy('mttr', 'desc');
        $sheets[] = new IssuesMetricSheetExport($issuesMttrQuery, 'Issues - MTTR', 'mttr');

        // 9. Issues - MTBF (simplified: Issue Name, Type, MTBF)
        $issuesMtbfQuery = $this->query->clone()
            ->where('classification', 'Issue')
            ->whereNotNull('mtbf')
            ->orderBy('mtbf', 'desc');
        $sheets[] = new IssuesMetricSheetExport($issuesMtbfQuery, 'Issues - MTBF', 'mtbf');

        return $sheets;
    }
}
