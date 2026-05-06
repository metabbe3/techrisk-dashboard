<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IssueResource\Pages;
use App\Models\Incident;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IssueResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-circle';

    protected static ?string $navigationLabel = 'Issues';

    protected static ?string $modelLabel = 'Issue';

    protected static ?string $pluralModelLabel = 'Issues';

    protected static ?int $navigationSort = 2; // After Incident (which is 1)

    public static function canCreate(): bool
    {
        return auth()->user()->can('manage issues');
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('manage issues');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->can('view issues');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('manage issues');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Issue Details')
                    ->schema([
                        TextInput::make('title')
                            ->label('Issue Name')
                            ->required()
                            ->autofocus()
                            ->columnSpanFull()
                            ->readOnly(fn ($context) => $context === 'edit'),
                        TextInput::make('no')
                            ->label('Issue ID')
                            ->default(fn () => Incident::generateNo('IS'))
                            ->readOnly()
                            ->unique(ignoreRecord: true)
                            ->columnSpan(1),
                        Select::make('classification')
                            ->default('Issue')
                            ->disabled()
                            ->hidden()
                            ->dehydrated(true),
                        DateTimePicker::make('incident_date')
                            ->label('Start Date')
                            ->required()
                            ->seconds(false)
                            ->columnSpan(1)
                            ->live(true)
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $set('entry_date_tech_risk', \Carbon\Carbon::parse($state)->format('Y-m-d'));
                                }
                            }),
                        DateTimePicker::make('stop_bleeding_at')
                            ->label('End Date')
                            ->seconds(false)
                            ->columnSpan(1),
                        DateTimePicker::make('entry_date_tech_risk')
                            ->label('Tech Risk Entry Date')
                            ->required()
                            ->seconds(false)
                            ->columnSpan(1)
                            ->default(fn () => now()->format('Y-m-d'))
                            ->hidden(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('incident_date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['pic', 'incidentType']))
            ->columns([
                TextColumn::make('no')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->summarize(Count::make()->label('Total')),
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->html()
                    ->formatStateUsing(function (Incident $record): string {
                        $record->title = str_replace('Summary of Incident - ', '', $record->title);

                        return view('components.incident-hover-preview', ['incident' => $record])->render();
                    }),
                TextColumn::make('mttr_formatted')
                    ->label('MTTR')
                    ->sortable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $direction) {
                        return $query->orderBy('mttr', $direction);
                    })
                    ->toggleable(),
                TextColumn::make('mtbf_display')
                    ->label('MTBF (days)')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->state(function (Incident $record): int {
                        static $cache = [];
                        $year = $record->incident_date->year;
                        $key = "issues_{$year}";

                        if (! isset($cache[$key])) {
                            $incidents = Incident::whereYear('incident_date', $year)
                                ->where('classification', 'Issue')
                                ->orderBy('incident_date')->orderBy('id')
                                ->get(['id', 'incident_date']);

                            $cache[$key] = [];
                            foreach ($incidents as $i => $inc) {
                                $cache[$key][$inc->id] = $i === 0
                                    ? $inc->incident_date->dayOfYear
                                    : (int) $incidents[$i - 1]->incident_date->startOfDay()
                                        ->diffInDays($inc->incident_date->startOfDay());
                            }
                        }

                        return $cache[$key][$record->id] ?? 0;
                    })
                    ->formatStateUsing(fn (int $state): string => number_format($state))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('incident_date', $direction)),
                TextColumn::make('incident_date')
                    ->label('Start Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('stop_bleeding_at')
                    ->label('End Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                \App\Filament\Filters\QuickPeriodFilter::make(),
                \App\Filament\Filters\QuickPeriodFilter::dateRange(),
            ])
            ->actions([
                Tables\Actions\Action::make('upgrade_to_incident')
                    ->label('Upgrade to Incident')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Upgrade to Incident')
                    ->modalDescription('This will reclassify this issue as an incident and assign a new Incident ID.')
                    ->action(fn (Incident $record) => $record->changeClassification('Incident'))
                    ->visible(fn (): bool => auth()->user()->can('manage issues')),
                Tables\Actions\EditAction::make()
                    ->databaseTransaction()
                    ->visible(fn (): bool => auth()->user()->can('manage issues')),
                Tables\Actions\DeleteAction::make()
                    ->databaseTransaction()
                    ->visible(fn (): bool => auth()->user()->can('manage issues')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulk_upgrade')
                        ->label('Upgrade to Incident')
                        ->icon('heroicon-o-arrow-up-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Upgrade selected issues to incidents')
                        ->modalDescription('All selected issues will be reclassified as incidents with new IDs.')
                        ->action(fn (\Illuminate\Support\Collection $records) => $records->each->changeClassification('Incident'))
                        ->visible(fn (): bool => auth()->user()->can('manage issues'))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->databaseTransaction()
                        ->visible(fn (): bool => auth()->user()->can('manage issues')),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIssues::route('/'),
            'create' => Pages\CreateIssue::route('/create'),
            'edit' => Pages\EditIssue::route('/{record}/edit'),
        ];
    }
}
