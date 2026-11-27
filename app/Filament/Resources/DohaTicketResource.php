<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DohaTicketResource\Pages\ListDohaTickets;
use App\Filament\Resources\DohaTicketResource\Pages\ViewDohaTicket;
use App\Filament\Traits\ReadOnlyResource;
use App\Models\DohaTicket;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only mirror of DOHA fund-loss tickets. Data is written ONLY by
 * DohaSyncService (scheduled + Sync now) — never from here. Free-text
 * DOHA fields (status/priority/domain) always get their filter options
 * from the data itself, never a fixed list.
 */
class DohaTicketResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = DohaTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 4; // After Incident (1), Issue (2), Action Improvements (3)

    protected static ?string $navigationLabel = 'DOHA Tickets';

    protected static ?string $modelLabel = 'DOHA Ticket';

    protected static ?string $pluralModelLabel = 'DOHA Tickets';

    public static function canViewAny(): bool
    {
        // Null guard: the Spotlight palette evaluates this on guest pages
        // (login) — a missing user must mean "no", never a 500.
        // Owner 2026-11-27: admin role OR the explicit permission (the
        // AiUsageLogResource pattern) — admins get the section as soon as
        // the code deploys, without waiting for the permission row to be
        // seeded; other roles still need the 'view doha tickets' grant.
        $user = auth()->user();

        return $user !== null && ($user->hasRole('admin') || $user->can('view doha tickets'));
    }

    public static function table(Table $table): Table
    {
        return $table
            // Owner 2026-11-24: newest DOHA transaction first (the ticket's
            // own incident date — not the sync time).
            ->defaultSort('transaction_date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('changes'))
            ->columns([
                // Owner 2026-11-25: the first three columns freeze on
                // horizontal scroll ("sampai header bank") — offsets
                // measured at runtime by app.js syncStickyIdWidths
                // (--doha-ref-w / --doha-title-w), rules in app.css.
                TextColumn::make('ref')
                    ->searchable()
                    ->sortable()
                    ->extraHeaderAttributes(['class' => 'sticky-col-doha-ref'])
                    ->extraCellAttributes(['class' => 'sticky-col-doha-ref']),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40)
                    ->extraHeaderAttributes(['class' => 'sticky-col-doha-title'])
                    ->extraCellAttributes(['class' => 'sticky-col-doha-title']),
                TextColumn::make('bank')
                    ->searchable()
                    ->extraHeaderAttributes(['class' => 'sticky-col-doha-bank'])
                    ->extraCellAttributes(['class' => 'sticky-col-doha-bank']),
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower((string) $state)) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower((string) $state)) {
                        'ongoing' => 'info',
                        'closed' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('potential_loss')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('recovered')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('transaction_date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('incident.no')
                    ->label('Incident')
                    ->placeholder('—')
                    ->url(fn (DohaTicket $record): ?string => $record->incident_id !== null
                        ? IncidentResource::getUrl('view', ['record' => $record->incident_id])
                        : null)
                    ->color('primary'),
                TextColumn::make('team')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_by')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('doha_updated_at')
                    ->label('Updated in DOHA')
                    ->state(fn (DohaTicket $record): ?string => $record->doha_updated_at_wib?->format('d/m/Y H:i'))
                    ->sortable(),
                // Owner 2026-11-24 (item 7): a ticket with recorded updates
                // carries a count badge; click-through is the detail timeline.
                TextColumn::make('changes_count')
                    ->label('Updates')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (int $state): ?string => $state > 0
                        ? $state.' update'.($state === 1 ? '' : 's')
                        : null)
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                // Free-text per the DOHA contract — options come from the data.
                SelectFilter::make('priority')
                    ->options(fn () => DohaTicket::query()
                        ->distinct()->orderBy('priority')->pluck('priority', 'priority')->filter()->all()),
                SelectFilter::make('status')
                    ->options(fn () => DohaTicket::query()
                        ->distinct()->orderBy('status')->pluck('status', 'status')->filter()->all()),
                TernaryFilter::make('linked')
                    ->label('Incident link')
                    ->nullable()
                    ->placeholder('All tickets')
                    ->trueLabel('Linked to an incident')
                    ->falseLabel('Unlinked — not yet an incident')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('incident_id'),
                        false: fn (Builder $query) => $query->whereNull('incident_id'),
                        blank: fn (Builder $query) => $query,
                    ),
                Filter::make('transaction_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),
            ])
            ->actions([
                // View + the bridge kebab ("...") right of it (owner
                // 2026-11-24). Row actions are Tables\Actions — page Actions
                // here would 500 on Action::table.
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    \App\Filament\Actions\DohaTicketIncidentActions::createIncidentRowAction(),
                ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No DOHA tickets')
            ->emptyStateDescription('Configure the DOHA token on the System Settings page and run Sync now.')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDohaTickets::route('/'),
            'view' => ViewDohaTicket::route('/{record}'),
        ];
    }
}
