<?php

namespace App\Filament\Resources\AiWorkflowResource\Pages;

use App\Filament\Resources\AiWorkflowResource;
use App\Models\AiWorkflow;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAiWorkflow extends ViewRecord
{
    protected static string $resource = AiWorkflowResource::class;

    protected static string $view = 'filament.resources.ai-workflow.view';

    public function getTitle(): string
    {
        return 'Workflow: '.$this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('run')
                ->label('Run workflow')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Dispatches the first agent now. Each next step is queued automatically as the previous one completes successfully.')
                ->action(function (): void {
                    /** @var AiWorkflow $workflow */
                    $workflow = $this->getRecord();
                    $first = $workflow->steps()->orderBy('position')->first()?->agent;

                    if (! $first || ! $first->enabled) {
                        Notification::make()->warning()->title('Nothing to run')->body('The first agent is missing or disabled.')->send();

                        return;
                    }

                    $run = $first->dispatchRun();
                    Notification::make()->success()->title('Workflow started')->body('Run dispatched — following steps queue automatically.')->send();
                }),
            \Filament\Actions\EditAction::make(),
        ];
    }

    protected function getViewData(): array
    {
        /** @var AiWorkflow $workflow */
        $workflow = $this->getRecord()->load('steps.agent');
        $steps = $workflow->steps;

        // Mermaid labels: sanitize to safe chars so quotes/specials can't break the graph.
        $label = fn (string $name): string => preg_replace('/[^A-Za-z0-9 _-]/', '', $name) ?: 'Agent';

        // Collections are uuid-keyed; values() reindexes so $i is the position.
        $ordered = $steps->values();
        $nodes = $ordered->map(fn ($step, $i) => sprintf('s%d["%d. %s"]', $i, $i + 1, $label($step->agent?->name ?? 'deleted')));
        $edges = $ordered->skip(1)->values()->map(fn ($step, $i) => sprintf('s%d --> s%d', $i, $i + 1));

        return [
            // values() keeps positional keys — the blade looks up step i-1 by index.
            'workflowSteps' => $ordered,
            'mermaid' => $steps->isEmpty() ? '' : "graph LR\n".implode("\n", $nodes->all())."\n".implode("\n", $edges->all()),
        ];
    }
}
