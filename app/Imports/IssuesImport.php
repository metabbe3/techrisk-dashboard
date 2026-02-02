<?php

namespace App\Imports;

use App\Models\Incident;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class IssuesImport implements SkipsEmptyRows, ToModel, WithHeadingRow, WithValidation
{
    private function generateIssueId(): string
    {
        $baseId = date('Ymd').'_IS_';
        $uniqueId = '';
        do {
            $suffix = random_int(1000, 9999);
            $uniqueId = $baseId.$suffix;
        } while (Incident::where('no', $uniqueId)->exists());

        return $uniqueId;
    }

    public function model(array $row)
    {
        $issue = new Incident([
            'title' => $row['title'],
            'incident_date' => Carbon::parse($row['incident_date']),
            'severity' => $row['severity'],
            'classification' => 'Issue',
            'no' => $this->generateIssueId(),
        ]);

        // Optional fields
        if (isset($row['stop_bleeding_at']) && ! empty($row['stop_bleeding_at'])) {
            $issue->stop_bleeding_at = Carbon::parse($row['stop_bleeding_at']);
        }

        // MTTR/MTBF will be auto-calculated by the IncidentObserver

        return $issue;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'incident_date' => 'required|date',
            'severity' => 'required|in:P1,P2,P3,P4,G,X1,X2,X3,X4',
            'stop_bleeding_at' => 'nullable|date',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'severity.in' => 'The severity must be one of: P1, P2, P3, P4, G, X1, X2, X3, X4',
        ];
    }
}
