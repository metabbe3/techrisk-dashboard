<?php

namespace App\Filament\Resources\ReportTemplateResource\Pages;

use App\Filament\Resources\ReportTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReportTemplate extends EditRecord
{
    protected static string $resource = ReportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->databaseTransaction(),
        ];
    }

    protected function useDatabaseTransactions(): bool
    {
        return true;
    }
}
