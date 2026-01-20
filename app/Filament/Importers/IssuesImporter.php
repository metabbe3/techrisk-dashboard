<?php

namespace App\Filament\Importers;

use App\Models\Incident;
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
        // Check if issue with same title exists (prevent duplicates from Notion bulk import)
        $name = $this->data['Name'] ?? null;
        if ($name) {
            $existing = Incident::where('classification', 'Issue')
                ->where('title', $name)
                ->first();

            if ($existing) {
                // Return existing record to update it instead of creating duplicate
                return $existing;
            }
        }

        // Create new record if not found
        return new Incident();
    }

    public function fillRecord(): void
    {
        // Parse date from "Month DD, YYYY" format (e.g., "January 12, 2026")
        $dateString = $this->data['Date of Incident'] ?? '';
        $incidentDate = $this->parseNotionDate($dateString);

        $data = [
            'title' => $this->data['Name'],
            'incident_date' => $incidentDate,
            'severity' => $this->mapSeverityToCode($this->data['Root Cause Classification'] ?? 'G'),
            'classification' => 'Issue',
        ];

        // Only generate new ID for new records (not when updating existing)
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
        $baseId = date('Ymd') . '_IS_';
        $uniqueId = '';
        do {
            $suffix = random_int(1000, 9999);
            $uniqueId = $baseId . $suffix;
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
        $count = $import->rows_successful ?: 0;
        return "Successfully imported {$count} issues.";
    }
}
