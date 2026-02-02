<?php

namespace App\Filament\Resources\ReportTemplateResource\Pages;

use App\Filament\Resources\ReportTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReportTemplate extends CreateRecord
{
    protected static string $resource = ReportTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function useDatabaseTransactions(): bool
    {
        return true;
    }
}
