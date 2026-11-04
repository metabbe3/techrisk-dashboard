<?php

declare(strict_types=1);

namespace App\Filament\Resources\IssueResource\Pages;

use App\Filament\Resources\IssueResource;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewIssue extends ViewRecord
{
    protected static string $resource = IssueResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Details')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->iconColor('primary')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('no')->label('ID'),
                        TextEntry::make('severity')->badge(),
                        TextEntry::make('incident_status')->label('Status')->badge(),
                        TextEntry::make('incident_date')->label('Date')->dateTime('Y-m-d'),
                    ]),

                // Notion page-body sections (NotionPageBodyParser) plus any
                // hand-written content — same fields, read-only.
                Section::make('Summary')
                    ->icon('heroicon-o-document-text')
                    ->iconColor('primary')
                    ->schema([
                        TextEntry::make('summary')->markdown()->hiddenLabel()->placeholder('No summary provided'),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                Section::make('Root Cause')
                    ->icon('heroicon-o-light-bulb')
                    ->iconColor('warning')
                    ->schema([
                        TextEntry::make('root_cause')->markdown()->hiddenLabel()->placeholder('No root cause recorded'),
                    ])
                    ->collapsible()
                    ->visible(fn ($record) => filled($record->root_cause))
                    ->columnSpanFull(),

                Section::make('Incident Timeline & Chronology')
                    ->icon('heroicon-o-clock')
                    ->iconColor('info')
                    ->schema([
                        TextEntry::make('timeline')->markdown()->hiddenLabel()->placeholder('No timeline recorded'),
                    ])
                    ->collapsible()
                    ->visible(fn ($record) => filled($record->timeline))
                    ->columnSpanFull(),

                Section::make('Action Taken')
                    ->icon('heroicon-o-check-circle')
                    ->iconColor('success')
                    ->schema([
                        TextEntry::make('improvements')->markdown()->hiddenLabel()->placeholder('No actions recorded'),
                    ])
                    ->collapsible()
                    ->visible(fn ($record) => filled($record->improvements))
                    ->columnSpanFull(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => auth()->user()->can('manage issues')),
        ];
    }
}
