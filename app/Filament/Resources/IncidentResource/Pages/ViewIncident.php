<?php

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Filament\Resources\IncidentResource;
use App\Services\Markdown\IncidentMarkdownExporter;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewIncident extends ViewRecord
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export_markdown')
                ->label('Export to Markdown')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->tooltip('Export this incident as a Markdown file for AI analysis')
                ->action(function (IncidentMarkdownExporter $exporter) {
                    return $exporter->download($this->getRecord());
                }),
            Actions\EditAction::make()
                ->visible(fn (): bool => auth()->user()->can('manage incidents')),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Grid::make(3)->schema([
                    Section::make('Core Details')
                        ->schema([
                            TextEntry::make('title'),
                            TextEntry::make('incidentType.name'),
                            TextEntry::make('summary')->columnSpanFull(),
                            TextEntry::make('root_cause')->columnSpanFull(),
                            TextEntry::make('timeline')
                                ->label('Incident Timeline and Chronology')
                                ->columnSpanFull(),
                            TextEntry::make('mttr')->label('MTTR (minutes)'),
                            TextEntry::make('mtbf')->label('MTBF (days)'),
                        ])->columnSpan(2),
                    Section::make('Status')
                        ->schema([
                            TextEntry::make('goc_upload')->label('GoC Uploaded'),
                            TextEntry::make('teams_upload')->label('Teams Uploaded'),
                            TextEntry::make('latestStatusUpdate.status')->label('Latest Status'),
                        ])->columnSpan(1),
                ]),
                Section::make('Triage & Impact')
                    ->schema([
                        TextEntry::make('severity'),
                        TextEntry::make('discovered_at')->dateTime(),
                        TextEntry::make('stop_bleeding_at')->dateTime(),
                        TextEntry::make('incident_date')->dateTime(),
                        TextEntry::make('entry_date_tech_risk')->date(),
                        TextEntry::make('pic.name')->label('PIC'),
                        TextEntry::make('reported_by'),
                        TextEntry::make('involved_third_party')->label('3rd Party/Client'),
                        TextEntry::make('potential_fund_loss')->money('IDR'),
                        TextEntry::make('fund_loss')->money('IDR'),
                        TextEntry::make('people_caused'),
                        TextEntry::make('checker'),
                        TextEntry::make('maker'),
                    ])->columns(4),
            ]);
    }
}
