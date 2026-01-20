<?php

namespace App\Filament\Importers;

use App\Models\Incident;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Illuminate\Support\Carbon;

class IssuesImporter extends Importer
{
    protected static ?string $model = Incident::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')
                ->label('Issue Name')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255']),
            ImportColumn::make('incident_date')
                ->label('Start Date')
                ->requiredMapping()
                ->rules(['required', 'date']),
            ImportColumn::make('severity')
                ->label('Incident Type')
                ->requiredMapping()
                ->rules(['required', 'in:P1,P2,P3,P4,G,X1,X2,X3,X4']),
            ImportColumn::make('stop_bleeding_at')
                ->label('End Date')
                ->rules(['nullable', 'date']),
        ];
    }

    public function resolveRecord(): ?Incident
    {
        // Always create new records (don't update existing)
        return new Incident();
    }

    public function fillRecord(): void
    {
        $this->record->fill([
            'title' => $this->data['title'],
            'incident_date' => Carbon::parse($this->data['incident_date']),
            'severity' => $this->data['severity'],
            'classification' => 'Issue',
            'no' => $this->generateIssueId(),
        ]);

        if (isset($this->data['stop_bleeding_at']) && !empty($this->data['stop_bleeding_at'])) {
            $this->record->stop_bleeding_at = Carbon::parse($this->data['stop_bleeding_at']);
        }

        // MTTR/MTBF will be auto-calculated by the IncidentObserver
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

    public static function getCompletedNotificationBody(Filament\Actions\Imports\Models\Import $import): string
    {
        $count = $import->rows_successful ?: 0;
        return "Successfully imported {$count} issues.";
    }
}
