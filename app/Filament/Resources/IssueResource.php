<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IssueResource\Pages;
use App\Models\Incident;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Components\Tab;

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
                            ->columnSpanFull(),
                        TextInput::make('no')
                            ->label('Issue ID')
                            ->default(function () {
                                $baseId = date('Ymd') . '_IS_';
                                $uniqueId = '';
                                do {
                                    $suffix = random_int(1000, 9999);
                                    $uniqueId = $baseId . $suffix;
                                } while (Incident::where('no', $uniqueId)->exists());
                                return $uniqueId;
                            })
                            ->readOnly()
                            ->columnSpan(1),
                        Select::make('severity')
                            ->label('Incident Type')
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
                        Select::make('classification')
                            ->default('Issue')
                            ->readOnly()
                            ->hidden()
                            ->dehydrated(true),
                        DateTimePicker::make('incident_date')
                            ->label('Start Date')
                            ->required()
                            ->seconds(false)
                            ->columnSpan(1),
                        DateTimePicker::make('stop_bleeding_at')
                            ->label('End Date')
                            ->seconds(false)
                            ->columnSpan(1),
                        TextInput::make('mttr')
                            ->label('MTTR (minutes)')
                            ->readOnly()
                            ->visible(fn ($context) => $context === 'edit')
                            ->columnSpan(1),
                        TextInput::make('mtbf')
                            ->label('MTBF (days)')
                            ->readOnly()
                            ->visible(fn ($context) => $context === 'edit')
                            ->columnSpan(1),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('incident_date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->where('classification', 'Issue'))
            ->columns([
                TextColumn::make('no')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('title')
                    ->label('Issue Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('severity')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'P1' => 'danger',
                        'P2' => 'warning',
                        'P3' => 'info',
                        'P4' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('mttr')
                    ->label('MTTR (mins)')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('mtbf')
                    ->label('MTBF (days)')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
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
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('update_status')
                    ->label('Update Status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->form([
                        Select::make('incident_status')
                            ->label('Status')
                            ->options([
                                'Open' => 'Open',
                                'In progress' => 'In progress',
                                'Finalization' => 'Finalization',
                                'Completed' => 'Completed',
                            ])
                            ->required()
                            ->default(fn (Incident $record) => $record->incident_status),
                    ])
                    ->action(function (Incident $record, array $data) {
                        $record->update([
                            'incident_status' => $data['incident_status'],
                        ]);
                    })
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
            'view' => Pages\ViewIssue::route('/{record}'),
        ];
    }
}
