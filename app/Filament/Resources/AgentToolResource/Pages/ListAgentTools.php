<?php

namespace App\Filament\Resources\AgentToolResource\Pages;

use App\Filament\Resources\AgentToolResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAgentTools extends ListRecords
{
    protected static string $resource = AgentToolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
