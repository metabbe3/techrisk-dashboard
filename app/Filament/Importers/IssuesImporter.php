<?php

namespace App\Filament\Importers;

use App\Helpers\StringHelper;
use App\Models\Incident;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Carbon;

class IssuesImporter extends Importer
{
    protected static ?string $model = Incident::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('Name')
                ->label('Name')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255']),
            ImportColumn::make('Date of Incident')
                ->label('Date of Incident')
                ->requiredMapping()
                ->rules(['required', 'string']),
            ImportColumn::make('Root Cause Classification')
                ->label('Root Cause Classification')
                ->requiredMapping()
                ->rules(['nullable', 'string']),
        ];
    }

    public function resolveRecord(): ?Incident
    {
        $name = $this->data['Name'] ?? null;

        if (!$name) {
            return new Incident();
        }

        // Normalize the incoming title for fuzzy comparison
        $normalizedTitle = StringHelper::normalizeForComparison($name);

        // Query all issues and filter using fuzzy matching
        $existing = Incident::where('classification', 'Issue')
            ->get()
            ->first(fn (Incident $incident) =>
                StringHelper::normalizeForComparison($incident->title) === $normalizedTitle
            );

        if ($existing) {
            // Skip this row with a warning message instead of updating
            throw new RowImportFailedException(
                "Skipped: Issue '{$name}' already exists as '{$existing->title}' (ID: {$existing->no})"
            );
        }

        // Create new record if no duplicate found
        return new Incident();
    }

    public function fillRecord(): void
    {
        // Parse date from "Month DD, YYYY" format (e.g., "January 12, 2026")
        $dateString = $this->data['Date of Incident'] ?? '';
        $incidentDate = $this->parseNotionDate($dateString);

        // Use StringHelper to clean the title (removes Notion prefix, trims, etc.)
        $rawName = $this->data['Name'] ?? '';
        $cleanTitle = str_replace('Summary of Incident - ', '', $rawName);

        $data = [
            'title' => $cleanTitle,
            'incident_date' => $incidentDate,
            'entry_date_tech_risk' => $incidentDate->format('Y-m-d'),
            'severity' => $this->mapSeverityToCode($this->data['Root Cause Classification'] ?? 'G'),
            'classification' => 'Issue',
            'incident_type_id' => \App\Models\IncidentType::first()?->id,
        ];

        // Only generate new ID for new records
        if (!$this->record->exists) {
            $data['no'] = $this->generateIssueId();
        }

        $this->record->fill($data);

        // MTTR/MTBF will be auto-calculated by the IncidentObserver
    }

    private function parseNotionDate(string $dateString): Carbon
    {
        // Try parsing "Month DD, YYYY" format from Notion
        try {
            return Carbon::createFromFormat('F d, Y', $dateString)->startOfDay();
        } catch (\Exception $e) {
            // Fallback to regular parsing
            return Carbon::parse($dateString);
        }
    }

    private function mapSeverityToCode(string $classification): string
    {
        // Map Notion classifications to severity codes
        $mapping = [
            'Deployment Issues' => 'P1',
            'Infrastructure Issue' => 'P1',
            'Code Bug' => 'P2',
            'Configuration Error' => 'P2',
            'Data Issue' => 'P3',
            'UI Issue' => 'P4',
            'Internal Improving Issue' => 'G',
        ];

        return $mapping[$classification] ?? 'G'; // Default to G (General)
    }

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

    public static function getLabel(): string
    {
        return 'Issues';
    }

    public static function getLabelLabel(): string
    {
        return 'Issue Name';
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $successful = $import->rows_successful ?? 0;
        $failed = $import->getFailedRowsCount() ?? 0;

        $message = "Successfully imported {$successful} issue(s).";

        if ($failed > 0) {
            $message .= " {$failed} row(s) skipped due to duplicates.";
        }

        return $message;
    }
}
