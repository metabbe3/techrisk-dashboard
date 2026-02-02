<?php

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Filament\Resources\IncidentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIncident extends CreateRecord
{
    protected static string $resource = IncidentResource::class;

    protected function useDatabaseTransactions(): bool
    {
        return true;
    }
}
