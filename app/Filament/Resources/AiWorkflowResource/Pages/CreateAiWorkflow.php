<?php

declare(strict_types=1);
namespace App\Filament\Resources\AiWorkflowResource\Pages;

use App\Filament\Resources\AiWorkflowResource;
use App\Services\Ai\AiWorkflowService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAiWorkflow extends CreateRecord
{
    protected static string $resource = AiWorkflowResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $record = static::getModel()::create($data);

        // steps repeater is dehydrated(false) — read it from the raw form state.
        $agentIds = array_column($this->data['steps'] ?? [], 'agent_id');
        app(AiWorkflowService::class)->saveSteps($record, $agentIds);

        return $record;
    }
}
