<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LabelResource\Pages;
use App\Filament\Resources\LabelResource\RelationManagers;
use App\Models\Label;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LabelResource extends Resource
{
    protected static ?string $model = Label::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Settings';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('view labels');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->databaseTransaction(),
                Tables\Actions\DeleteAction::make()
                    ->databaseTransaction()
                    ->visible(fn (): bool => auth()->user()->can('manage labels')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->databaseTransaction()
                        ->visible(fn (): bool => auth()->user()->can('manage labels')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLabels::route('/'),
            'create' => Pages\CreateLabel::route('/create'),
            'edit' => Pages\EditLabel::route('/{record}/edit'),
        ];
    }
}
