<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IncidentResource\Pages;
use App\Filament\Resources\IncidentResource\RelationManagers;
use App\Models\Incident;
use App\Models\UserAuditLogSetting;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    public static function canCreate(): bool
    {
        return auth()->user()->can('manage incidents');
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('manage incidents');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(3)->schema([
                    Section::make('Core Details')
                        ->schema([
                            TextInput::make('title')->required(),
                            TextInput::make('no')->label('Incident ID')
                                ->required()
                                ->default(function () {
                                    $baseId = date('Ymd').'_IN_';
                                    $uniqueId = '';
                                    do {
                                        $suffix = random_int(1000, 9999);
                                        $uniqueId = $baseId.$suffix;
                                    } while (Incident::where('no', $uniqueId)->exists());

                                    return $uniqueId;
                                })
                                ->readOnly(),
                            Select::make('severity')->options([
                                'P1' => 'P1', 'P2' => 'P2', 'P3' => 'P3', 'P4' => 'P4', 'G' => 'G', 'X1' => 'X1', 'X2' => 'X2', 'X3' => 'X3', 'X4' => 'X4', 'Non Incident' => 'Non Incident',
                            ])->required(),
                            Select::make('classification')->options([
                                'Incident' => 'Incident', 'Issue' => 'Issue',
                            ])->required(),
                            TextInput::make('reported_by')->label('Reported By'),
                            TextInput::make('mttr')->label('MTTR (minutes)')->readOnly()->visible(fn ($context) => $context === 'edit'),
                            TextInput::make('mtbf')->label('MTBF (days)')->readOnly()->visible(fn ($context) => $context === 'edit'),
                        ])->columnSpan(2),

                    Section::make('Admin & Upload Status')
                        ->schema([
                            Checkbox::make('goc_upload')->label('GoC Uploaded'),
                            Checkbox::make('teams_upload')->label('Teams Uploaded'),
                            Checkbox::make('doc_signed')->label('Doc Signed'),
                            Checkbox::make('risk_incident_form_cfm')->label('Risk Incident Form CFM'),
                        ])->columnSpan(1),
                ]),
                Section::make('Timeline')
                    ->schema([
                        DateTimePicker::make('incident_date')->label('Occurred Time')->required(),
                        DateTimePicker::make('discovered_at'),
                        DateTimePicker::make('stop_bleeding_at'),
                        DateTimePicker::make('entry_date_tech_risk')->required(),
                    ])->columns(4),

                Section::make('Financial Impact')
                    ->schema([
                        Select::make('fund_status')->options([
                            'Non fundLoss' => 'Non fundLoss',
                            'Confirmed loss' => 'Confirmed loss',
                            'Potential recovery' => 'Potential recovery',
                        ]),
                        TextInput::make('potential_fund_loss')->numeric()->prefix('Rp')->default(0),
                        TextInput::make('recovered_fund')->numeric()->prefix('Rp')->default(0)->required(),
                        TextInput::make('fund_loss')->numeric()->prefix('Rp')->default(0)->required(),
                    ])->columns(4),

                Section::make('Analysis & Root Cause')
                    ->schema([
                        Select::make('incident_status')->options([
                            'Open' => 'Open',
                            'In progress' => 'In progress',
                            'Finalization' => 'Finalization',
                            'Completed' => 'Completed',
                        ])->required()->default('Open'),
                        Select::make('incident_type')->label('Area')->options(['Tech' => 'Tech', 'Non-tech' => 'Non-tech'])->required(),
                        Select::make('incident_source')->options(['Internal' => 'Internal', 'External' => 'External'])->required(),
                        Select::make('pic_id')
                            ->label('Person In Charge')
                            ->relationship('pic', 'name')
                            ->searchable()
                            ->preload(),
                    ])->columns(4),

                Section::make('Details & Timeline')
                    ->schema([
                        Textarea::make('summary')
                            ->label('Summary')
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('root_cause')
                            ->label('Root Cause')
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('timeline')
                            ->label('Incident Timeline and Chronology')
                            ->rows(10)
                            ->helperText('Describe the sequence of events chronologically')
                            ->columnSpanFull(),
                        Textarea::make('remark')
                            ->label('Remark')
                            ->rows(4)
                            ->columnSpanFull(),
                        Select::make('labels')
                            ->multiple()
                            ->relationship('labels', 'name')
                            ->preload()
                            ->searchable(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => self::applyAccessControl($query))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['pic', 'incidentType']))
            ->defaultSort('incident_date', 'desc')
            ->columns([
                TextColumn::make('no')->label('ID')->searchable()->sortable()->summarize(Count::make()->label('Total Cases')),
                TextColumn::make('title')->searchable()->limit(30),
                TextColumn::make('mttr_formatted')->label('MTTR (mins)')->sortable(query: function (Builder $query, string $direction) {
                    return $query->orderBy('mttr', $direction);
                }),
                TextColumn::make('mtbf_display')->label('MTBF (days)')->sortable(),
                TextColumn::make('severity')->badge()->color(fn (string $state): string => match ($state) {
                    'P1' => 'danger',
                    'P2' => 'warning',
                    'P3' => 'info',
                    'P4' => 'success',
                    'N' => 'success',
                    'G' => 'success',
                    default => 'gray',
                })->sortable(),
                TextColumn::make('incident_status')->badge()->color(fn (string $state): string => match ($state) {
                    'Open' => 'warning', 'In progress' => 'info', 'Finalization' => 'primary', 'Completed' => 'success', default => 'gray',
                })->sortable(),
                TextColumn::make('fund_status')->badge()->color(fn (string $state): string => match ($state) {
                    'Confirmed loss' => 'danger', 'Non fundLoss' => 'success', 'Potential recovery' => 'warning', default => 'gray',
                })->sortable()->toggleable(),
                TextColumn::make('pic.name')->label('PIC')->sortable()->toggleable(),
                TextColumn::make('incident_date')->dateTime()->sortable(),
                TextColumn::make('potential_fund_loss')->label('Potential Loss')->money('IDR')->sortable()->summarize(Sum::make()->money('IDR')->label('Total Potential')),
                TextColumn::make('recovered_fund')->label('Recovered')->money('IDR')->sortable()->color('success')->summarize(Sum::make()->money('IDR')->label('Total Recovered')),
                TextColumn::make('fund_loss')->label('Actual Loss')->money('IDR')->sortable()->color('danger')->summarize(Sum::make()->money('IDR')->label('Total Loss')),
                TextColumn::make('recovery_rate')->label('Recovery %')->state(function (Incident $record): string {
                    if ($record->potential_fund_loss && $record->potential_fund_loss > 0) {
                        $rate = ($record->recovered_fund / $record->potential_fund_loss) * 100;

                        return number_format($rate, 1).'%';
                    }

                    return '0%';
                })->color(fn (string $state): string => (floatval($state) >= 100) ? 'success' : ((floatval($state) > 0) ? 'warning' : 'gray')),

                // Toggleable Hidden Columns
                TextColumn::make('classification')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('incident_type')->label('Area')->sortable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('goc_upload')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                'incident_status',
                'severity',
                Group::make('incident_date')
                    ->label('Incident Month')
                    ->getTitleFromRecordUsing(fn (Incident $record): ?string => $record->incident_date?->format('F Y'))
                    ->collapsible(),
            ])
            ->filters([
                // Multi-Column Sort Filter
                SelectFilter::make('multi_column_sort')
                    ->label('Sort By')
                    ->options([
                        'date_desc' => 'Date (Newest First)',
                        'date_asc' => 'Date (Oldest First)',
                        'date_status' => 'Date → Status (Open First)',
                        'status_date' => 'Status → Date (Open First)',
                        'status_date_asc' => 'Status → Date (Oldest First)',
                        'status_severity' => 'Status → Severity (P1 First)',
                        'severity_date' => 'Severity → Date (P1 First)',
                        'pic_date' => 'PIC → Date',
                        'mttr_desc' => 'MTTR (Highest First)',
                        'loss_desc' => 'Fund Loss (Highest First)',
                    ])
                    ->indicateUsing(function (array $data): ?string {
                        $value = $data['value'] ?? null;
                        if ($value === null) {
                            return null;
                        }

                        $labels = [
                            'date_desc' => 'Sort: Date (Newest)',
                            'date_asc' => 'Sort: Date (Oldest)',
                            'date_status' => 'Sort: Date → Status',
                            'status_date' => 'Sort: Status → Date (Newest)',
                            'status_date_asc' => 'Sort: Status → Date (Oldest)',
                            'status_severity' => 'Sort: Status → Severity',
                            'severity_date' => 'Sort: Severity → Date',
                            'pic_date' => 'Sort: PIC → Date',
                            'mttr_desc' => 'Sort: MTTR',
                            'loss_desc' => 'Sort: Fund Loss',
                        ];

                        return $labels[$value] ?? "Sort: {$value}";
                    })
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;

                        // If no filter value selected, don't apply any custom sorting
                        // Let the defaultSort() and column sorting handle it
                        if ($value === null) {
                            return $query;
                        }

                        return match ($value) {
                            'date_desc' => $query->orderBy('incident_date', 'desc'),
                            'date_asc' => $query->orderBy('incident_date', 'asc'),
                            'date_status' => $query->orderBy('incident_date', 'desc')
                                ->orderByRaw("FIELD(incident_status, 'Open', 'In progress', 'Finalization', 'Completed')"),
                            'status_date' => $query->orderByRaw("FIELD(incident_status, 'Open', 'In progress', 'Finalization', 'Completed')")
                                ->orderBy('incident_date', 'desc'),
                            'status_date_asc' => $query->orderByRaw("FIELD(incident_status, 'Open', 'In progress', 'Finalization', 'Completed')")
                                ->orderBy('incident_date', 'asc'),
                            'status_severity' => $query->orderByRaw("FIELD(incident_status, 'Open', 'In progress', 'Finalization', 'Completed')")
                                ->orderByRaw("FIELD(severity, 'P1', 'P2', 'P3', 'P4', 'G', 'X1', 'X2', 'X3', 'X4', 'Non Incident')"),
                            'severity_date' => $query->orderByRaw("FIELD(severity, 'P1', 'P2', 'P3', 'P4', 'G', 'X1', 'X2', 'X3', 'X4', 'Non Incident')")
                                ->orderBy('incident_date', 'desc'),
                            'pic_date' => $query->orderBy('pic_id')->orderBy('incident_date', 'desc'),
                            'mttr_desc' => $query->orderBy('mttr', 'desc')->orderBy('incident_date', 'desc'),
                            'loss_desc' => $query->orderBy('fund_loss', 'desc')->orderBy('incident_date', 'desc'),
                            default => $query,
                        };
                    }),

                // 1. Quick Filter for Presets
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

                // 2. Custom Date Range Filter
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

                // 3. Fund Status Filter
                SelectFilter::make('fund_status')
                    ->label('Fund Status')
                    ->options([
                        'Confirmed loss' => 'Fund Loss',
                        'Non fundLoss' => 'Non Fund Loss',
                        'Potential recovery' => 'Potential Recovery',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('update_status')
                    ->label('Update Status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->form([
                        Forms\Components\Select::make('incident_status')
                            ->label('Status')
                            ->options([
                                'Open' => 'Open',
                                'In progress' => 'In progress',
                                'Finalization' => 'Finalization',
                                'Completed' => 'Completed',
                            ])
                            ->required()
                            ->default(fn (Incident $record) => $record->incident_status),
                        Forms\Components\DateTimePicker::make('update_date')
                            ->label('Update Date')
                            ->seconds(false)
                            ->default(fn (Incident $record) => $record->incident_date)
                            ->required(),
                        Forms\Components\Textarea::make('remark')
                            ->label('Notes')
                            ->required()
                            ->rows(3)
                            ->default(fn (Incident $record) => $record->remark),
                    ])
                    ->action(function (Incident $record, array $data) {
                        $record->update([
                            'incident_status' => $data['incident_status'],
                            'incident_date' => $data['update_date'],
                            'remark' => $data['remark'],
                        ]);
                    })
                    ->visible(fn (): bool => auth()->user()->can('manage incidents')),
                Tables\Actions\EditAction::make()
                    ->databaseTransaction()
                    ->visible(fn (): bool => auth()->user()->can('manage incidents')),
                Tables\Actions\DeleteAction::make()
                    ->databaseTransaction()
                    ->visible(fn (): bool => auth()->user()->can('manage incidents')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->databaseTransaction()
                        ->visible(fn (): bool => auth()->user()->can('manage incidents')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\StatusUpdatesRelationManager::class,
            RelationManagers\InvestigationDocumentsRelationManager::class,
            RelationManagers\AuditsRelationManager::class,
            RelationManagers\ActionImprovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncidents::route('/'),
            'create' => Pages\CreateIncident::route('/create'),
            'edit' => Pages\EditIncident::route('/{record}/edit'),
            'view' => Pages\ViewIncident::route('/{record}'),
        ];
    }

    /**
     * Apply access control based on user's year permissions.
     * Admins see all years, non-admins see only their allowed years.
     */
    protected static function applyAccessControl(Builder $query): Builder
    {
        // Always exclude Issues from IncidentResource
        $query = $query->where('classification', '!=', 'Issue');

        $user = auth()->user();
        if (! $user) {
            return $query;
        }

        // Admins see all data
        if ($user->hasRole('admin')) {
            return $query;
        }

        // Non-admins: filter by allowed years
        $settings = UserAuditLogSetting::forUser($user);

        if (! $settings->can_view_all_logs && ! empty($settings->allowed_years)) {
            $query->whereYear('incident_date', $settings->allowed_years);
        }

        return $query;
    }
}
