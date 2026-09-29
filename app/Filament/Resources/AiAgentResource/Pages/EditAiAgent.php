<?php

declare(strict_types=1);
namespace App\Filament\Resources\AiAgentResource\Pages;

use App\Filament\Resources\AiAgentResource;
use Filament\Resources\Pages\EditRecord;

class EditAiAgent extends EditRecord
{
    protected static string $resource = AiAgentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Reverse-parse frequency+cron into the preset fields the form shows.
        [$data['schedule_type'], $data['run_time'], $data['run_weekday']] = array_values(
            $this->getRecord()->schedulePresetFromCron()
        );

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AiAgentResource::resolveScheduleData($data);
    }
}
