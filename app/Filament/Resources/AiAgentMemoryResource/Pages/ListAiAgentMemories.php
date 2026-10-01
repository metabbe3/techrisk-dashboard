<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiAgentMemoryResource\Pages;

use App\Filament\Resources\AiAgentMemoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiAgentMemories extends ListRecords
{
    protected static string $resource = AiAgentMemoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The single owner-requested button: rebuild the long-term
            // incident memory every AI feature reads (markdown/corpus).
            // Auto-refreshes hourly via incidents:refresh-corpus.
            Actions\Action::make('rebuild_incident_corpus')
                ->label('Rebuild Incident Memory')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Rebuild incident memory')
                ->modalDescription('Rebuilds the persistent incident memory: every incident (P1–P4/X1–X4, no Issues) as markdown plus converted investigation documents. AI agents read it as long-term memory. It also auto-refreshes hourly; this forces a rebuild now.')
                ->action(function () {
                    $manifest = app(\App\Services\Markdown\IncidentMarkdownCorpusService::class)->refresh();

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Incident memory rebuilt')
                        ->body($manifest['incidents'].' incidents · '.now()->format('d M Y H:i'))
                        ->send();
                }),
        ];
    }
}
