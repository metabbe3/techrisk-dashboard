<?php

namespace App\Filament\Resources\IncidentTypeResource\Pages;

use App\Filament\Resources\IncidentTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIncidentType extends CreateRecord
{
    protected static string $resource = IncidentTypeResource::class;

    protected function useDatabaseTransactions(): bool
    {
        return true;
    }
}
