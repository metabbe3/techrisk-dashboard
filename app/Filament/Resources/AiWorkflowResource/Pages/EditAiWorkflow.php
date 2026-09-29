<?php

namespace App\Filament\Resources\AiWorkflowResource\Pages;

use App\Filament\Resources\AiWorkflowResource;
use App\Models\AiWorkflow;
use App\Services\Ai\AiWorkflowService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAiWorkflow extends EditRecord
{
    protected static string $resource = AiWorkflowResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var AiWorkflow $record */
        $record = $this->getRecord();

        $data['steps'] = $record->steps()->orderBy('position')->get()
            ->map(fn ($step) => ['agent_id' => $step->agent_id])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update($data);

        $agentIds = array_column($this->data['steps'] ?? [], 'agent_id');
        app(AiWorkflowService::class)->saveSteps($record, $agentIds);

        return $record;
    }
}
