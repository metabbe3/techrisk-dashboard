<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiUsageLogResource\Pages\ListAiUsageLogs;
use App\Filament\Traits\ReadOnlyResource;
use App\Models\AiUsageLog;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AiUsageLogResource extends Resource
{
    use ReadOnlyResource;
    protected static ?string $model = AiUsageLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 101;

    protected static ?string $navigationLabel = 'AI Usage Logs';

    protected static ?string $modelLabel = 'AI Usage Log';

    protected static ?string $pluralModelLabel = 'AI Usage Logs';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user->hasRole('admin') || $user->can('view ai usage logs');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                if (! auth()->user()->hasRole('admin')) {
                    $query->where('user_id', auth()->id());
                }
            })
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('requested_at')
                    ->label('Time')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn (AiUsageLog $record): string => ($record->response_time_ms ?? '?').'ms'),

                TextColumn::make('user_email')
                    ->label('User')
                    ->searchable()
                    ->toggleable()
                    ->visible(fn () => auth()->user()->hasRole('admin')),

                TextColumn::make('field_type')
                    ->label('Field')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'summary' => 'info',
                        'root_cause' => 'warning',
                        'timeline' => 'success',
                        'remark' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('model')
                    ->searchable()
                    ->badge()
                    ->color('primary'),

                TextColumn::make('total_tokens')
                    ->label('Tokens')
                    ->numeric()
                    ->sortable()
                    ->description(fn (AiUsageLog $record): string => ($record->prompt_tokens ?? 0).' in / '.($record->completion_tokens ?? 0).' out')
                    ->toggleable(),

                TextColumn::make('response_time_ms')
                    ->label('Latency')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => $state.'ms')
                    ->color(fn ($state): string => match (true) {
                        $state < 3000 => 'success',
                        $state < 8000 => 'warning',
                        default => 'danger',
                    }),

                IconColumn::make('success')
                    ->label('Status')
                    ->boolean()
                    ->sortable()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                TextColumn::make('error_message')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('model')
                    ->options(fn () => AiUsageLog::query()
                        ->distinct()
                        ->pluck('model', 'model')
                        ->filter()
                        ->toArray()),

                SelectFilter::make('field_type')
                    ->options([
                        'summary' => 'Summary',
                        'root_cause' => 'Root Cause',
                        'timeline' => 'Timeline',
                        'remark' => 'Remark',
                    ]),

                SelectFilter::make('success')
                    ->label('Status')
                    ->options([
                        '1' => 'Success',
                        '0' => 'Failed',
                    ]),

                SelectFilter::make('user_id')
                    ->label('User')
                    ->options(fn () => AiUsageLog::query()
                        ->distinct()
                        ->pluck('user_email', 'user_id')
                        ->filter()
                        ->toArray())
                    ->searchable()
                    ->visible(fn () => auth()->user()->hasRole('admin')),

                Filter::make('date_range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('From')
                            ->maxDate(fn () => now()),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('To')
                            ->maxDate(fn () => now()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('requested_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('requested_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist(fn (AiUsageLog $record): array => [
                        Section::make('Request')
                            ->schema([
                                TextEntry::make('requested_at')->dateTime('d/m/Y H:i:s'),
                                TextEntry::make('user_email')->label('User'),
                                TextEntry::make('field_type')->formatStateUsing(fn ($state) => ucfirst(str_replace('_', ' ', $state))),
                                TextEntry::make('model'),
                                TextEntry::make('input_length')->label('Input Length')->suffix(' chars'),
                                TextEntry::make('output_length')->label('Output Length')->suffix(' chars'),
                            ])->columns(2),

                        Section::make('Token Usage')
                            ->schema([
                                TextEntry::make('prompt_tokens')->label('Prompt Tokens'),
                                TextEntry::make('completion_tokens')->label('Completion Tokens'),
                                TextEntry::make('total_tokens')->label('Total Tokens'),
                                TextEntry::make('api_request_id')->label('API Request ID'),
                            ])->columns(2),

                        Section::make('Performance')
                            ->schema([
                                TextEntry::make('response_time_ms')->label('Response Time')->suffix(' ms'),
                                TextEntry::make('success')
                                    ->badge()
                                    ->color(fn ($state) => $state ? 'success' : 'danger')
                                    ->formatStateUsing(fn ($state) => $state ? 'Success' : 'Failed'),
                            ])->columns(2),

                        Section::make('Error')
                            ->schema([
                                TextEntry::make('error_message')->markdown(),
                            ])
                            ->visible(fn () => filled($record->error_message))
                            ->collapsible(),
                    ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No AI usage logs')
            ->emptyStateDescription('AI enhancement requests will appear here.')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiUsageLogs::route('/'),
        ];
    }
}
