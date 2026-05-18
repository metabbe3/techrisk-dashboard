<?php

namespace App\Filament\Resources\AgentToolResource\Pages;

use App\Filament\Resources\AgentToolResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAgentTool extends EditRecord
{
    protected static string $resource = AgentToolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
