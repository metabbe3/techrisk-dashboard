<?php

namespace App\Filament\Resources\WarRoomAgentConfigResource\Pages;

use App\Filament\Resources\WarRoomAgentConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWarRoomAgentConfigs extends ListRecords
{
    protected static string $resource = WarRoomAgentConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
