<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiAgentMemoryResource\Pages;

use App\Filament\Resources\AiAgentMemoryResource;
use App\Filament\Widgets\IncidentMemoryStatusWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListAiAgentMemories extends ListRecords
{
    protected static string $resource = AiAgentMemoryResource::class;

    protected function getHeaderWidgets(): array
    {
        // The memory's persistent status: the rebuild notification fades,
        // this stays (owner 2026-10-01 — "not showed on ai-agent-memories?").
        return [IncidentMemoryStatusWidget::class];
    }

    protected function getHeaderActions(): array
    {
        $corpus = fn () => app(\App\Services\Markdown\IncidentMarkdownCorpusService::class);

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
                ->action(function ($livewire) use ($corpus) {
                    $manifest = $corpus()->refresh();

                    // Live-update the status widget — without this the panel
                    // keeps its old numbers until a full page reload.
                    $livewire->dispatch('incident-memory-rebuilt');

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Incident memory rebuilt')
                        ->body($manifest['incidents'].' incidents · '.now()->format('d M Y H:i'))
                        ->send();
                }),

            // See what the memory actually holds: index.md in a read-only
            // modal. Hidden until the first build exists.
            Actions\Action::make('view_incident_catalog')
                ->label('View catalog')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->visible(fn () => $corpus()->catalog() !== null)
                ->modalHeading('Incident catalog (index.md)')
                ->modalContent(fn () => new HtmlString(
                    // No hardcoded surface color — a light-only background makes
                    // the text invisible in Filament dark mode; the panel theme
                    // supplies the <pre>'s background and text color.
                    '<pre style="white-space:pre-wrap;font-size:12px;max-height:500px;overflow-y:auto;padding:16px;border-radius:8px;">'
                    .e($corpus()->catalog() ?? '')
                    .'</pre>'
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
        ];
    }
}
