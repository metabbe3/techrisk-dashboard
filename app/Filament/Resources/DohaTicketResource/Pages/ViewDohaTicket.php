<?php

declare(strict_types=1);

namespace App\Filament\Resources\DohaTicketResource\Pages;

use App\Filament\Actions\DohaSyncAction;
use App\Filament\Actions\DohaTicketIncidentActions;
use App\Filament\Resources\DohaTicketResource;
use App\Filament\Resources\IncidentResource;
use App\Models\DohaTicket;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewDohaTicket extends ViewRecord
{
    protected static string $resource = DohaTicketResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Ticket')->schema([
                TextEntry::make('ref'),
                TextEntry::make('title'),
                TextEntry::make('domain'),
                TextEntry::make('bank'),
                TextEntry::make('team'),
                TextEntry::make('category'),
                TextEntry::make('business_type'),
                TextEntry::make('priority')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('service_impact')->markdown()->columnSpanFull(),
            ])->columns(2)->collapsible(),

            Section::make('Financial impact')->schema([
                TextEntry::make('total_transaction')->numeric(),
                TextEntry::make('potential_loss')->money('IDR'),
                TextEntry::make('recovered')->money('IDR'),
                TextEntry::make('transaction_date')->date('d/m/Y'),
                TextEntry::make('transaction_time')->label('Transaction time (WIB)'),
                TextEntry::make('close_date')->date('d/m/Y')
                    ->placeholder('Still open'),
            ])->columns(3)->collapsible(),

            Section::make('Actions taken')->schema([
                TextEntry::make('recovery_action')->markdown(),
                TextEntry::make('follow_up_action')->markdown(),
            ])->columns(1)->collapsible(),

            Section::make('People & time')->schema([
                TextEntry::make('created_by')->label('Created by (DOHA)'),
                TextEntry::make('created_by_edit')->label('Last edited by (DOHA)'),
                TextEntry::make('doha_created_at')
                    ->label('Created in DOHA (WIB)')
                    ->state(fn (DohaTicket $record): ?string => $record->doha_created_at_wib?->format('d/m/Y H:i')),
                TextEntry::make('doha_updated_at')
                    ->label('Updated in DOHA (WIB)')
                    ->state(fn (DohaTicket $record): ?string => $record->doha_updated_at_wib?->format('d/m/Y H:i')),
            ])->columns(2)->collapsible(),

            Section::make('Linked incident')
                ->schema([
                    TextEntry::make('incident.no')
                        ->label('Incident')
                        ->url(fn (DohaTicket $record): ?string => $record->incident_id !== null
                            ? IncidentResource::getUrl('view', ['record' => $record->incident_id])
                            : null)
                        ->color('primary'),
                ])
                ->description(fn (DohaTicket $record): string => $record->incident_id !== null
                    ? 'This fund-loss case is recorded as an incident.'
                    : 'Not linked — this fund-loss case is not yet an incident here.')
                ->columns(1),

            Section::make('Change history')
                ->schema([
                    RepeatableEntry::make('changes')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('field'),
                            TextEntry::make('old_value')->label('From')->placeholder('—'),
                            TextEntry::make('new_value')->label('To')->placeholder('—'),
                            TextEntry::make('created_at')
                                ->label('Observed')
                                ->dateTime('d/m/Y H:i'),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ])
                ->description(fn (DohaTicket $record): string => $record->changes()->count() === 0
                    ? 'No changes observed yet.'
                    : $record->changes()->count().' change(s) observed by the sync, newest first.')
                ->visible(fn (DohaTicket $record): bool => $record->changes()->exists())
                ->columns(1),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DohaTicketIncidentActions::createIncidentAction(),
            DohaTicketIncidentActions::linkAction(),
            DohaTicketIncidentActions::unlinkAction(),
            DohaSyncAction::make(),
        ];
    }
}
