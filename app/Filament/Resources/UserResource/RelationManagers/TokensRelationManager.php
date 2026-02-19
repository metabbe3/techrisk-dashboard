<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\ApiEndpoint;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'API Tokens';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Tokens are managed through the ApiTokenResource
                Tables\Columns\TextColumn::make('info')
                    ->content('API tokens are managed through the API Tokens resource in the User Management section.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Manage API tokens for this user')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->dateTime('M d, Y H:i')
                    ->default('Never')
                    ->sortable()
                    ->color(fn ($state): string => ! $state ? 'gray' : (now()->diffInDays($state) > 25 ? 'danger' : 'success')
                    ),

                Tables\Columns\TextColumn::make('allowed_endpoints')
                    ->label('Endpoint Access')
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(function ($state): string {
                        if (empty($state)) {
                            return 'All Endpoints';
                        }

                        $count = count($state);

                        return $count.' endpoint'.($count > 1 ? 's' : '');
                    })
                    ->tooltip(function ($record): ?string {
                        if (empty($record->allowed_endpoints)) {
                            return 'Unrestricted access to all endpoints';
                        }

                        return collect($record->allowed_endpoints)
                            ->map(fn ($endpoint) => ApiEndpoint::tryFrom($endpoint)?->label() ?? $endpoint)
                            ->join(', ');
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M d, Y')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->label('Revoke')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke API Token')
                    ->modalDescription('Are you sure you want to revoke this token? This action cannot be undone.')
                    ->modalSubmitActionLabel('Revoke Token'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
