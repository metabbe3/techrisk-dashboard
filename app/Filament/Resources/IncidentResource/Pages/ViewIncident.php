<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Filament\Resources\IncidentResource;
use App\Services\Markdown\IncidentMarkdownExporter;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
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
                ViewEntry::make('incident_hero')
                    ->view('filament.resources.incident-resource.pages.incident-hero')
                    ->columnSpanFull(),

                Section::make('Summary')
                    ->icon('heroicon-o-document-text')
                    ->iconColor('primary')
                    ->schema([
                        TextEntry::make('summary')
                            ->markdown()
                            ->hiddenLabel()
                            ->placeholder('No summary provided'),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                Section::make('Root Cause Analysis')
                    ->icon('heroicon-o-light-bulb')
                    ->iconColor('warning')
                    ->schema([
                        TextEntry::make('root_cause')
                            ->markdown()
                            ->hiddenLabel()
                            ->placeholder('No root cause analysis'),
                        TextEntry::make('root_cause_category')
                            ->label('Root Cause Category')
                            ->badge()
                            ->color('warning')
                            ->separator(','),
                        TextEntry::make('responsible_team')
                            ->label('Responsible Team')
                            ->badge()
                            ->color('violet')
                            ->separator(','),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                Section::make('Incident Timeline & Chronology')
                    ->icon('heroicon-o-clock')
                    ->iconColor('info')
                    ->schema([
                        TextEntry::make('timeline')
                            ->markdown()
                            ->hiddenLabel()
                            ->placeholder('No timeline recorded'),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                Section::make('Remark')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->iconColor('violet')
                    ->schema([
                        TextEntry::make('remark')
                            ->markdown()
                            ->hiddenLabel()
                            ->placeholder('No remarks'),
                    ])
                    ->collapsible()
                    ->visible(fn ($record) => filled($record->remark))
                    ->columnSpanFull(),

                Section::make('Labels')
                    ->icon('heroicon-o-tag')
                    ->iconColor('teal')
                    ->schema([
                        TextEntry::make('labels.name')
                            ->label('Labels')
                            ->badge()
                            ->color('primary')
                            ->separator(','),
                    ])
                    ->visible(fn ($record) => $record->labels && $record->labels->isNotEmpty())
                    ->columnSpanFull(),

                Grid::make(2)->schema([
                    Section::make('Triage Details')
                        ->icon('heroicon-o-bolt')
                        ->iconColor('rose')
                        ->schema([
                            TextEntry::make('severity')
                                ->badge()
                                ->color(fn ($state) => Severity::tryFrom($state)?->color() ?? 'gray'),
                            TextEntry::make('incident_status')
                                ->label('Status')
                                ->badge()
                                ->color(fn ($state) => IncidentStatus::tryFrom($state)?->color() ?? 'gray'),
                            TextEntry::make('incident_date')
                                ->label('Incident Date')
                                ->dateTime('d M Y, H:i'),
                            TextEntry::make('discovered_at')
                                ->dateTime('d M Y, H:i'),
                            TextEntry::make('stop_bleeding_at')
                                ->label('Stop Bleeding At')
                                ->dateTime('d M Y, H:i'),
                            TextEntry::make('entry_date_tech_risk')
                                ->label('Entry Date Tech Risk')
                                ->date('d M Y'),
                        ])->columns(2)->columnSpan(1),

                    Section::make('Financial Impact')
                        ->icon('heroicon-o-banknotes')
                        ->iconColor('amber')
                        ->schema([
                            TextEntry::make('fund_status')
                                ->badge()
                                ->color(fn ($state) => FundStatus::tryFrom($state)?->color() ?? 'gray'),
                            TextEntry::make('potential_fund_loss')
                                ->money('IDR')
                                ->color('warning'),
                            TextEntry::make('fund_loss')
                                ->label('Actual Fund Loss')
                                ->money('IDR')
                                ->color('danger'),
                            TextEntry::make('recovered_fund')
                                ->money('IDR')
                                ->color('success'),
                            TextEntry::make('loss_taken_by'),
                        ])->columns(2)->columnSpan(1),
                ]),

                Grid::make(2)->schema([
                    Section::make('Source & Assignment')
                        ->icon('heroicon-o-user-group')
                        ->iconColor('indigo')
                        ->schema([
                            TextEntry::make('incident_source')
                                ->label('Source')
                                ->badge(),
                            TextEntry::make('pic.name')
                                ->label('PIC'),
                            TextEntry::make('reported_by'),
                            TextEntry::make('checker'),
                            TextEntry::make('maker'),
                            TextEntry::make('third_party_client')
                                ->label('3rd Party/Client'),
                        ])->columns(2)->columnSpan(1),

                    Section::make('Categories & Metrics')
                        ->icon('heroicon-o-chart-bar')
                        ->iconColor('cyan')
                        ->schema([
                            TextEntry::make('business_category')
                                ->label('Business Category')
                                ->badge()
                                ->color('info')
                                ->separator(','),
                            TextEntry::make('classification')
                                ->badge()
                                ->color('gray'),
                            TextEntry::make('incident_type')
                                ->label('Area')
                                ->badge(),
                            TextEntry::make('incidentType.name')
                                ->label('Incident Type'),
                            TextEntry::make('mttr')
                                ->label('MTTR')
                                ->suffix(' mins'),
                            TextEntry::make('mtbf')
                                ->label('MTBF')
                                ->suffix(' days'),
                        ])->columns(2)->columnSpan(1),
                ]),

                Section::make('Admin & Flags')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->iconColor('gray')
                    ->schema([
                        TextEntry::make('goc_upload')->label('GoC Uploaded')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                        TextEntry::make('teams_upload')->label('Teams Uploaded')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                        TextEntry::make('doc_signed')->label('Doc Signed')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                        TextEntry::make('risk_incident_form_cfm')->label('Risk Form CFM')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                        TextEntry::make('glitch_flag')->label('Glitch')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'danger' : 'gray'),
                    ])
                    ->columns(5)
                    ->collapsible()
                    ->columnSpanFull(),

                Section::make('Evidence')
                    ->icon('heroicon-o-paper-clip')
                    ->iconColor('yellow')
                    ->schema([
                        TextEntry::make('evidence')
                            ->markdown()
                            ->hiddenLabel()
                            ->placeholder('No evidence text'),
                        TextEntry::make('evidence_link')
                            ->label('Evidence Link')
                            ->url(fn ($state) => $state)
                            ->openUrlInNewTab(),
                    ])
                    ->collapsible()
                    ->visible(fn ($record) => filled($record->evidence) || filled($record->evidence_link))
                    ->columnSpanFull(),
            ]);
    }
}
