<?php

namespace App\Filament\Resources\AiAgentResource\Pages;

use App\Filament\Resources\AiAgentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiAgents extends ListRecords
{
    protected static string $resource = AiAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
