<?php

declare(strict_types=1);
namespace App\Filament\Resources;

use App\Enums\AiAgentRunStatus;
use App\Filament\Resources\AiAgentRunResource\Pages;
use App\Filament\Traits\ReadOnlyResource;
use App\Models\AiAgentRun;
use App\Services\Ai\AiAgentMemoryService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AiAgentRunResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = AiAgentRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Agent Runs';

    protected static ?string $modelLabel = 'Agent Run';

    protected static ?string $pluralModelLabel = 'Agent Runs';

    protected static ?int $navigationSort = 91;

    protected static ?string $navigationGroup = 'AI';

    public static function canViewAny(): bool
    {
        return auth()->check() && auth()->user()->can('manage api tokens');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('requested_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('requested_at')
                    ->label('Requested')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn (AiAgentRun $record): string => ($record->response_time_ms ?? '?').'ms'),
                Tables\Columns\TextColumn::make('agent.name')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof AiAgentRunStatus ? $state->value : (string) $state)
                    ->color(fn ($state): string => $state instanceof AiAgentRunStatus ? $state->color() : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('model')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('total_tokens')
                    ->label('Tokens')
                    ->description(fn (AiAgentRun $record): string => ($record->prompt_tokens ?? 0).' in / '.($record->completion_tokens ?? 0).' out')
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('agent')
                    ->relationship('agent', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->options(AiAgentRunStatus::options()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('give_feedback')
                    ->label('Give feedback')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->visible(fn (AiAgentRun $record): bool => $record->status === AiAgentRunStatus::Completed)
                    ->modalDescription('Stored in the agent\'s shared memory and injected into this agent\'s future runs (needs "Shared agent memory" enabled on the agent).')
                    ->form([
                        Forms\Components\Textarea::make('feedback')
                            ->label('What should this agent learn from this run?')
                            ->required()
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->action(function (array $data, AiAgentRun $record): void {
                        app(AiAgentMemoryService::class)->recordFeedback($record, $data['feedback']);

                        Notification::make()
                            ->success()
                            ->title('Feedback saved')
                            ->body('Future runs of this agent will see it in their shared memory.')
                            ->send();
                    }),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Run')
                    ->schema([
                        Infolists\Components\TextEntry::make('agent.name')
                            ->badge()
                            ->color('info'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof AiAgentRunStatus ? $state->value : (string) $state)
                            ->color(fn ($state): string => $state instanceof AiAgentRunStatus ? $state->color() : 'gray'),
                        Infolists\Components\TextEntry::make('model')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('requested_at')->dateTime('d/m/Y H:i:s'),
                        Infolists\Components\TextEntry::make('started_at')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                        Infolists\Components\TextEntry::make('completed_at')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                        Infolists\Components\TextEntry::make('response_time_ms')
                            ->label('Duration')
                            ->formatStateUsing(fn ($state): string => $state ? number_format($state).' ms' : '—'),
                        Infolists\Components\TextEntry::make('total_tokens')
                            ->formatStateUsing(fn (AiAgentRun $record): string => ($record->prompt_tokens ?? 0).' in / '.($record->completion_tokens ?? 0).' out / '.($record->total_tokens ?? 0).' total'),
                        Infolists\Components\TextEntry::make('error_message')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])->columns(3),

                // Plain escaped entries only — LLM output must never render as HTML.
                Infolists\Components\Section::make('Output')
                    ->schema([
                        Infolists\Components\TextEntry::make('output')
                            ->columnSpanFull()
                            ->placeholder('(no output)'),
                    ]),
                Infolists\Components\Section::make('Instructions snapshot')
                    ->schema([
                        Infolists\Components\TextEntry::make('input')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiAgentRuns::route('/'),
        ];
    }
}
