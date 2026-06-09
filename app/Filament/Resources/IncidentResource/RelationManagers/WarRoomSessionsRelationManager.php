<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarRoomSessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'warRoomSessions';

    protected static ?string $title = 'AI Retrospective Sessions';

    protected static ?string $icon = 'heroicon-o-chat-bubble-left-right';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->hasRole('admin') && auth()->user()?->can('access war room');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40)
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('current_round')
                    ->label('Round')
                    ->formatStateUsing(fn ($record) => "{$record->current_round}/{$record->max_rounds}"),
                TextColumn::make('selected_agents')
                    ->label('Agents')
                    ->formatStateUsing(fn ($record) => count($record->selected_agents ?? []))
                    ->alignCenter(),
                TextColumn::make('user.name')
                    ->label('Initiated By'),
                TextColumn::make('started_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('started_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => route('filament.admin.pages.war-room').'?session='.$record->id, true)
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No discussions yet')
            ->emptyStateDescription('This incident has not been analyzed in AI Retrospective.')
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right');
    }
}
