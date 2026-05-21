<?php

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Filament\Resources\IncidentResource;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;

class CreateIncident extends CreateRecord
{
    protected static string $resource = IncidentResource::class;

    public function form(Form $form): Form
    {
        return $form
            ->schema(IncidentResource::getFormSchema('create'))
            ->columns(12);
    }

    protected function useDatabaseTransactions(): bool
    {
        return true;
    }
}
