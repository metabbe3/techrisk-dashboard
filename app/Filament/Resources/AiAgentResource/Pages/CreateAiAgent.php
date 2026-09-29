<?php

namespace App\Filament\Resources\AiAgentResource\Pages;

use App\Filament\Resources\AiAgentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiAgent extends CreateRecord
{
    protected static string $resource = AiAgentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AiAgentResource::resolveScheduleData($data);
    }
}
