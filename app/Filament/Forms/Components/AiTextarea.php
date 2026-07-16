<?php

namespace App\Filament\Forms\Components;

use App\Services\Ai\AiTextService;
use Closure;
use Filament\Forms\Components\Textarea;

class AiTextarea extends Textarea
{
    protected string $view = 'filament.forms.components.ai-textarea';

    protected string|Closure|null $aiFieldType = null;

    public function aiFieldType(string|Closure $type): static
    {
        $this->aiFieldType = $type;

        return $this;
    }

    public function getAiFieldType(): string
    {
        return $this->evaluate($this->aiFieldType) ?? 'summary';
    }

    public function isAiAvailable(): bool
    {
        return app(AiTextService::class)->isAvailable();
    }

    public function getAiConfig(): array
    {
        $fieldType = $this->getAiFieldType();
        $aiService = app(AiTextService::class);

        $models = $aiService->getModelsForPicker();

        return [
            'fieldType' => $fieldType,
            'fieldName' => $this->getName(),
            'statePath' => $this->getStatePath(),
            'endpoint' => route('ai.enhance-text'),
            'csrfToken' => csrf_token(),
            'isAvailable' => $this->isAiAvailable(),
            'models' => $models,
            'defaultModel' => 'gpt-4',
            'promptLabel' => config("ai.prompts.{$fieldType}.label", 'Enhance with AI'),
            'recordId' => $this->getRecord()?->id,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->view('filament.forms.components.ai-textarea');
    }
}
