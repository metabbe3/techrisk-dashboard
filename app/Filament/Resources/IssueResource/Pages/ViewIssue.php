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

    /**
     * PROJ-018's first infolist override, merged with the issue-sync's
     * page-body sections: the record's own fields (the four visible form
     * fields — the hidden classification/entry_date_tech_risk were never
     * rendered — plus the sync-era severity/status), the Notion page-body
     * fields the sync filled, then the shared Notion Investigation section.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Issue Details')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('no')->label('Issue ID'),
                        TextEntry::make('title')->label('Issue Name'),
                        TextEntry::make('severity')->badge(),
                        TextEntry::make('incident_status')->label('Status')->badge(),
                        TextEntry::make('incident_date')->label('Start Date')->dateTime('Y-m-d'),
                        TextEntry::make('stop_bleeding_at')->label('End Date')->dateTime('Y-m-d'),
                    ]),

                // Notion page-body sections (NotionPageBodyParser) plus any
                // hand-written content — same fields, read-only. Markdown
                // collapses single newlines, so stored line breaks (each
                // "HH:MM →" timeline entry, "• " action lines, "**Label**"
                // rows) become hard breaks before rendering.
                Section::make('Summary')
                    ->icon('heroicon-o-document-text')
                    ->iconColor('primary')
                    ->schema([
                        TextEntry::make('summary')->formatStateUsing(self::hardBreaks(...))->markdown()->hiddenLabel()->placeholder('No summary provided'),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                Section::make('Root Cause')
                    ->icon('heroicon-o-light-bulb')
                    ->iconColor('warning')
                    ->schema([
                        TextEntry::make('root_cause')->formatStateUsing(self::hardBreaks(...))->markdown()->hiddenLabel()->placeholder('No root cause recorded'),
                    ])
                    ->collapsible()
                    ->visible(fn ($record) => filled($record->root_cause))
                    ->columnSpanFull(),

                Section::make('Incident Timeline & Chronology')
                    ->icon('heroicon-o-clock')
                    ->iconColor('info')
                    ->schema([
                        TextEntry::make('timeline')->formatStateUsing(self::hardBreaks(...))->markdown()->hiddenLabel()->placeholder('No timeline recorded'),
                    ])
                    ->collapsible()
                    ->visible(fn ($record) => filled($record->timeline))
                    ->columnSpanFull(),

                Section::make('Action Taken')
                    ->icon('heroicon-o-check-circle')
                    ->iconColor('success')
                    ->schema([
                        TextEntry::make('improvements')->formatStateUsing(self::hardBreaks(...))->markdown()->hiddenLabel()->placeholder('No actions recorded'),
                    ])
                    ->collapsible()
                    ->visible(fn ($record) => filled($record->improvements))
                    ->columnSpanFull(),

                NotionInvestigationAction::section(),
            ]);
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

    /** Markdown hard break: single \n → "  \n"; blank lines (\n\n) stay paragraph breaks. */
    private static function hardBreaks($state)
    {
        return is_string($state) && $state !== ''
            ? preg_replace('/(?<!\n)\n(?!\n)/', "  \n", $state)
            : $state;
    }
}
