<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IncidentTypeResource\Pages;
use App\Models\IncidentType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IncidentTypeResource extends Resource
{
    protected static ?string $model = IncidentType::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Settings';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('view incident types');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->databaseTransaction(),
                Tables\Actions\DeleteAction::make()
                    ->databaseTransaction()
                    ->visible(fn (): bool => auth()->user()->can('manage incident types')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->databaseTransaction()
                        ->visible(fn (): bool => auth()->user()->can('manage incident types')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncidentTypes::route('/'),
            'create' => Pages\CreateIncidentType::route('/create'),
            'edit' => Pages\EditIncidentType::route('/{record}/edit'),
        ];
    }
}
