<?php

namespace App\Filament\Resources\LabelResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditsRelationManager extends RelationManager
{
    protected static string $relationship = 'audits';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('User'),
                TextColumn::make('event')->label('Event'),
                TextColumn::make('old_values')->label('Old Values')->formatStateUsing(fn ($state) => json_encode($state)),
                TextColumn::make('new_values')->label('New Values')->formatStateUsing(fn ($state) => json_encode($state)),
                TextColumn::make('created_at')->label('Date')->dateTime(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ]);
    }
}
