<?php

namespace App\Filament\Resources;

use App\Enums\AiAgentMemoryKind;
use App\Filament\Resources\AiAgentMemoryResource\Pages;
use App\Models\AiAgentMemory;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AiAgentMemoryResource extends Resource
{
    protected static ?string $model = AiAgentMemory::class;

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?string $navigationLabel = 'Agent Memory';

    protected static ?string $modelLabel = 'Agent Memory';

    protected static ?string $pluralModelLabel = 'Agent Memory';

    protected static ?int $navigationSort = 93;

    protected static ?string $navigationGroup = 'AI';

    public static function canViewAny(): bool
    {
        return auth()->check() && auth()->user()->can('manage api tokens');
    }

    public static function canCreate(): bool
    {
        return false; // written only by agent runs
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('agent.name')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof AiAgentMemoryKind ? $state->value : (string) $state)
                    ->color(fn ($state): string => $state instanceof AiAgentMemoryKind ? $state->color() : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('content')
                    ->limit(80)
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('agent')
                    ->relationship('agent', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('kind')
                    ->options(AiAgentMemoryKind::options()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Memory')
                    ->schema([
                        Infolists\Components\TextEntry::make('agent.name')
                            ->badge()
                            ->color('info'),
                        Infolists\Components\TextEntry::make('kind')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof AiAgentMemoryKind ? $state->value : (string) $state)
                            ->color(fn ($state): string => $state instanceof AiAgentMemoryKind ? $state->color() : 'gray'),
                        Infolists\Components\TextEntry::make('created_at')->dateTime('d/m/Y H:i:s'),
                        Infolists\Components\TextEntry::make('run_id')
                            ->label('From run')
                            ->placeholder('— (run pruned or unknown)'),
                    ])->columns(2),

                // Plain escaped entries only — LLM output must never render as HTML.
                Infolists\Components\Section::make('Content')
                    ->schema([
                        Infolists\Components\TextEntry::make('content')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiAgentMemories::route('/'),
        ];
    }
}
