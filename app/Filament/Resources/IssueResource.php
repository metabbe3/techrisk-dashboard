<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IssueResource\Pages;
use App\Models\Incident;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
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
                            ->default(function () {
                                $baseId = date('Ymd').'_IS_';
                                $uniqueId = '';
                                do {
                                    $suffix = random_int(1000, 9999);
                                    $uniqueId = $baseId.$suffix;
                                } while (Incident::where('no', $uniqueId)->exists());

                                return $uniqueId;
                            })
                            ->readOnly()
                            ->columnSpan(1),
                        Select::make('severity')
                            ->label('Severity')
                            ->options([
                                'P1' => 'P1',
                                'P2' => 'P2',
                                'P3' => 'P3',
                                'P4' => 'P4',
                                'G' => 'G',
                                'X1' => 'X1',
                                'X2' => 'X2',
                                'X3' => 'X3',
                                'X4' => 'X4',
                            ])
                            ->required()
                            ->columnSpan(1),
                        Select::make('incident_type_id')
                            ->label('Incident Type')
                            ->relationship('incidentType', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn () => \App\Models\IncidentType::first()?->id)
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('incidentType'))
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
                    ->formatStateUsing(fn (string $state): string => str_replace('Summary of Incident - ', '', $state)),
                TextColumn::make('severity')
                    ->label('Severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'P1' => 'danger',
                        'P2' => 'warning',
                        'P3' => 'info',
                        'P4' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('classification')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Incident' => 'danger',
                        'Issue' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('incidentType.name')
                    ->label('Incident Type')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('mttr')
                    ->label('MTTR')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->summarize(Average::make()->label('Avg MTTR')),
                TextColumn::make('mtbf_all')
                    ->label('MTBF (All)')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->summarize(Average::make()->label('Avg MTBF')),
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
                // Quick Period Filter
                SelectFilter::make('quick_period')
                    ->label('Quick Period')
                    ->options([
                        'week' => 'This Week',
                        'month' => 'This Month',
                        'year' => 'This Year',
                        'all' => 'All Time',
                    ])
                    ->default('year')
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'];
                        if ($value === 'week') {
                            return $query->whereBetween('incident_date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                        }
                        if ($value === 'month') {
                            return $query->whereBetween('incident_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]);
                        }
                        if ($value === 'year') {
                            return $query->whereBetween('incident_date', [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()]);
                        }
                        if ($value === 'all') {
                            return $query;
                        }

                        return $query;
                    }),

                // Custom Date Range Filter
                Filter::make('custom_date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From Date'),
                        Forms\Components\DatePicker::make('until')->label('To Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('incident_date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('incident_date', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->databaseTransaction()
                    ->visible(fn (): bool => auth()->user()->can('manage issues')),
                Tables\Actions\DeleteAction::make()
                    ->databaseTransaction()
                    ->visible(fn (): bool => auth()->user()->can('manage issues')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
