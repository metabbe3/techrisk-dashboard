<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatusUpdatesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusUpdates';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('status')->options(['Open' => 'Open', 'Investigation' => 'Investigation', 'Monitoring' => 'Monitoring', 'Resolved' => 'Resolved', 'Closed' => 'Closed', 'Recovered' => 'Recovered'])->required(),
            DateTimePicker::make('update_date')->default(now()),
            Textarea::make('notes')->required()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('status')
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Open' => 'warning',
                        'Investigation' => 'info',
                        'Monitoring' => 'primary',
                        'Resolved' => 'success',
                        'Recovered' => 'success',
                        'Closed' => 'danger',
                        default => 'secondary',
                    }),
                TextColumn::make('notes')->wrap()->limit(50),
                TextColumn::make('update_date')->label('Update Date')->dateTime(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('update_date', 'desc');
    }
}
