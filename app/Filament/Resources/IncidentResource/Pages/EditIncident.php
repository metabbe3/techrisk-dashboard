<?php

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Filament\Resources\IncidentResource;
use Filament\Actions;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;

class EditIncident extends EditRecord
{
    protected static string $resource = IncidentResource::class;

    public function form(Form $form): Form
    {
        return $form
            ->schema(IncidentResource::getFormSchema('edit'))
            ->columns(12);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()->databaseTransaction(),
        ];
    }

    protected function useDatabaseTransactions(): bool
    {
        return true;
    }

    public function getHeaderWidgets(): array
    {
        return [];
    }

    public function getFooterWidgets(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing(['pic', 'labels', 'incidentType', 'latestStatusUpdate']);

        return $data;
    }

    protected function beforeSave(): void
    {
        $fields = ['summary', 'root_cause', 'timeline', 'remark'];
        $aiFields = $this->record->ai_enhanced_fields ?? [];
        $changed = false;

        foreach ($fields as $field) {
            if (! isset($aiFields[$field]['enhanced'])) {
                continue;
            }

            $submitted = trim((string) ($this->data[$field] ?? ''));
            $storedHash = $aiFields[$field]['hash'] ?? null;

            if ($storedHash && md5($submitted) !== $storedHash) {
                unset($aiFields[$field]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->record->ai_enhanced_fields = empty($aiFields) ? null : $aiFields;
            $this->record->saveQuietly();
        }
    }
}
