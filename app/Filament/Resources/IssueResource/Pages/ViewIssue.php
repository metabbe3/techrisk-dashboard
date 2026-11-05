<?php

declare(strict_types=1);

namespace App\Filament\Resources\IssueResource\Pages;

use App\Filament\Actions\NotionInvestigationAction;
use App\Filament\Resources\IssueResource;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewIssue extends ViewRecord
{
    protected static string $resource = IssueResource::class;

    public function mount(string|int $record): void
    {
        parent::mount($record);

        // Round 8: the investigation body self-refreshes on open when stale —
        // permission-gated here, quiet in the action.
        if (auth()->user()->can('manage issues')) {
            NotionInvestigationAction::autoRefresh($this->getRecord());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // Round 8: Refresh/Unlink moved into the Notion Investigation
            // section footer (next to "Open in Notion") — the kebab is gone.
            Actions\EditAction::make()
                ->visible(fn (): bool => auth()->user()->can('manage issues')),
        ];
    }

    /**
     * PROJ-018: first infolist override — until now this page rendered the
     * disabled form. The four visible form fields are reproduced as entries
     (the hidden classification/entry_date_tech_risk were never rendered),
     * then the shared Notion Investigation section.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Issue Details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('no')->label('Issue ID'),
                        TextEntry::make('title')->label('Issue Name'),
                        TextEntry::make('incident_date')->label('Start Date')->dateTime(),
                        TextEntry::make('stop_bleeding_at')->label('End Date')->dateTime(),
                    ]),

                NotionInvestigationAction::section(),
            ]);
    }
}
