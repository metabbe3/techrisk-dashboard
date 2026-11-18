<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Filament\Resources\ActionImprovementResource\Pages;
use App\Filament\Resources\ActionImprovementResource\RelationManagers;
use App\Models\ActionImprovement;
use App\Models\Incident;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global Action Improvements page (PROJ-014, owner 2026-10-14): every action
 * across all incidents, filtered/sortable — answers "what's overdue across
 * the org" without opening incidents one by one. Rows are still edited here
 * with the same shared form as the incident relation manager; view/edit/write
 * gates come from ActionImprovementPolicy (viewAny: view incidents OR access
 * api). Year access mirrors IssueResource: the incident's year decides.
 */
class ActionImprovementResource extends Resource
{
    protected static ?string $model = ActionImprovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Action Improvements';

    protected static ?int $navigationSort = 3; // After Incident (1) and Issue (2)

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['incident', 'completedBy'])
            // Year access on the incident half — same scope as IssueResource.
            ->whereHas('incident', fn (Builder $query) => $query->applyUserYearAccess(auth()->user()))
            // TBD rows sort last under the default due_date sort (NULLs
            // otherwise lead on MySQL/SQLite ASC) — urgent dates stay on top.
            ->orderByRaw('due_date IS NULL');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(ActionImprovementFormSchema::schema());
    }

    public static function table(Table $table): Table
    {
        $overdue = fn ($query) => $query
            ->whereIn('status', ActionImprovement::openStatuses())
            ->whereDate('due_date', '<', today());

        return $table
            ->defaultSort('due_date')
            ->groups([
                $incidentGroup = Group::make('incident.no')
                    ->label('Incident')
                    ->getTitleFromRecordUsing(fn ($record): string => $record->incident
                        ? "{$record->incident->no} — {$record->incident->title}"
                        : '—')
                    // Owner 2026-10-14: groups ordered by incident recency
                    // (not the "no" string) so the latest incident is first.
                    ->orderQueryUsing(fn ($query, $direction) => $query->orderBy(
                        Incident::select('incident_date')
                            ->whereColumn('incidents.id', 'action_improvements.incident_id'),
                        $direction,
                    ))
                    ->collapsible(),
                Group::make('pic_email')
                    ->label('PIC')
                    // Array cast — one group key per record: groups by the
                    // exact email combo (per-person grouping needs a real
                    // relation, owner's "later").
                    ->getKeyFromRecordUsing(fn ($record): string => implode(', ', $record->pic_email ?? []))
                    ->getTitleFromRecordUsing(fn ($record): string => implode(', ', $record->pic_email ?? []) ?: 'Unassigned'),
                Group::make('status')
                    ->getTitleFromRecordUsing(fn ($record): string => ucfirst((string) $record->status)),
                Group::make('priority')
                    ->getTitleFromRecordUsing(fn ($record): string => ucfirst((string) $record->priority)),
            ])
            // Owner 2026-10-14: open grouped by incident — "which incident is
            // this on" at a glance; ungroup/switch from the dropdown.
            ->defaultGroup($incidentGroup)
            ->columns([
                TextColumn::make('incident.no')
                    ->label('Incident No')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->url(fn ($record): ?string => $record->incident
                        ? IncidentResource\Pages\EditIncident::getUrl(['record' => $record->incident])
                        : null),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(35)
                    ->description(fn ($record): ?string => \Illuminate\Support\Str::limit($record->detail, 60)),
                TextColumn::make('incident.title')
                    ->label('Incident Title')
                    ->searchable()
                    ->url(fn ($record): ?string => $record->incident
                        ? IncidentResource\Pages\EditIncident::getUrl(['record' => $record->incident])
                        : null)
                    ->color('primary')
                    ->limit(25)
                    ->description(fn ($record): ?string => $record->incident
                        ? strtoupper($record->incident->severity?->value ?? '').' · '.($record->incident->incident_status?->value ?? '')
                        : null),
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => ActionImprovementFormSchema::priorityColor($state))
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date()
                    ->placeholder('TBD')
                    ->sortable()
                    // Overdue = danger (open work past due — pending OR
                    // on_progress); open TBD = warning (tracked, not
                    // forgotten — nothing else ever surfaces them).
                    ->color(fn ($record): string => $record->isOpenWork()
                        && $record->due_date?->isBefore(today()) ? 'danger'
                        : ($record->isOpenWork() && $record->due_date === null ? 'warning' : 'default'))
                    ->weight(fn ($record): string => $record->isOpenWork()
                        && $record->due_date?->isBefore(today()) ? 'bold' : 'normal')
                    ->description(fn ($record): ?string => $record->isOpenWork()
                        && $record->due_date?->isBefore(today()) ? 'overdue' : null),
                TextColumn::make('pic_email')
                    ->badge()
                    ->limitList(2),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => ActionImprovementFormSchema::statusColor($state))
                    ->sortable(),
                TextColumn::make('escalation_level')
                    ->label('Escalation')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => 'L'.$state)
                    ->color('danger')
                    ->description(fn ($record): ?string => $record->last_escalated_at?->format('M d, Y'))
                    ->visible(fn ($record): bool => ($record->escalation_level ?? 0) > 0)
                    ->toggleable(),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->placeholder('—')
                    ->description(fn ($record): ?string => trim(($record->completedBy?->name ?? '').($record->result_note ? ' · '.\Illuminate\Support\Str::limit($record->result_note, 40) : '')) ?: null)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->date('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('reminder')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reminder_frequency')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_reminded_at')
                    ->label('Last email')
                    ->dateTime('M d, Y')
                    ->placeholder('—')
                    ->description(fn ($record): ?string => $record->reminders_sent > 0 ? $record->reminders_sent.' reminder(s) emailed' : null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ActionImprovementFormSchema::statusOptions()),
                SelectFilter::make('priority')
                    ->options(array_combine(ActionImprovement::PRIORITIES, array_map('ucfirst', ActionImprovement::PRIORITIES))),
                TernaryFilter::make('overdue')
                    ->queries(
                        $overdue,
                        fn ($query) => $query
                            ->where(fn ($q) => $q
                                ->whereNotIn('status', ActionImprovement::openStatuses())
                                ->orWhereDate('due_date', '>=', today())
                                ->orWhereNull('due_date')), // TBD can't be past due
                    ),
                // Owner 2026-10-14: TBD rows are trackable — nothing else ever
                // surfaces them (reminders/overdue pass them by).
                TernaryFilter::make('tbd')
                    ->label('TBD (no due date)')
                    ->queries(
                        fn ($query) => $query->whereNull('due_date'),
                        fn ($query) => $query->whereNotNull('due_date'),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('open_incident')
                    ->label('Open Incident')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record): ?string => $record->incident
                        ? IncidentResource\Pages\EditIncident::getUrl(['record' => $record->incident])
                        : null),
                ActionImprovementFormSchema::rewordAction(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EvidenceRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActionImprovements::route('/'),
            'edit' => Pages\EditActionImprovement::route('/{record}/edit'),
        ];
    }
}
