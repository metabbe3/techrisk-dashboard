<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiWorkflowResource\Pages;
use App\Models\AiAgent;
use App\Models\AiWorkflow;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AiWorkflowResource extends Resource
{
    protected static ?string $model = AiWorkflow::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Workflows';

    protected static ?string $modelLabel = 'Workflow';

    protected static ?string $pluralModelLabel = 'Workflows';

    protected static ?int $navigationSort = 94;

    protected static ?string $navigationGroup = 'AI';

    public static function canViewAny(): bool
    {
        return auth()->check() && auth()->user()->can('manage api tokens');
    }

    /**
     * Agents available as steps: not already used by ANOTHER workflow
     * (unique constraint — one chain per agent).
     */
    public static function availableAgents(?AiWorkflow $record = null): array
    {
        $usedElsewhere = \App\Models\AiWorkflowStep::query()
            ->when($record, fn ($q) => $q->where('workflow_id', '!=', $record->id))
            ->pluck('agent_id');

        return AiAgent::query()->whereKeyNot($usedElsewhere)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Workflow')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(120),
                        Forms\Components\TextInput::make('description')
                            ->maxLength(255)
                            ->placeholder('e.g. Nightly digest → review → executive brief'),
                    ])->columns(2),

                Forms\Components\Section::make('Steps')
                    ->description('Drag to reorder. Each step runs after the one above it completes successfully; the chain follows any trigger on the first agent.')
                    ->schema([
                        Forms\Components\Repeater::make('steps')
                            ->label('Agents in order')
                            ->dehydrated(false)
                            ->reorderable()
                            ->addable()
                            ->deletable()
                            ->schema([
                                Forms\Components\Select::make('agent_id')
                                    ->options(fn (?AiWorkflow $record): array => static::availableAgents($record))
                                    ->searchable()
                                    ->required(),
                            ])
                            ->itemLabel(fn (array $state): string => AiAgent::find($state['agent_id'] ?? null)?->name ?? 'New step')
                            ->columns(1)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->description(fn (AiWorkflow $record): string => (string) $record->description),
                Tables\Columns\TextColumn::make('steps_count')
                    ->counts('steps')
                    ->label('Steps')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('firstAgent.name')
                    ->label('Starts with')
                    ->badge()
                    ->color('success')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('run')
                    ->label('Run workflow')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Dispatches the first agent now. Each next step is queued automatically as the previous one completes successfully.')
                    ->visible(fn (AiWorkflow $record): bool => $record->steps()->exists())
                    ->action(function (AiWorkflow $record): void {
                        $first = $record->steps()->orderBy('position')->first()?->agent;

                        if (! $first || ! $first->enabled) {
                            Notification::make()->warning()->title('Nothing to run')->body('The first agent is missing or disabled.')->send();

                            return;
                        }

                        $run = $first->dispatchRun();
                        Notification::make()->success()->title('Workflow started')->body("Run {$run->id} dispatched — following steps queue automatically.")->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiWorkflows::route('/'),
            'create' => Pages\CreateAiWorkflow::route('/create'),
            'edit' => Pages\EditAiWorkflow::route('/{record}/edit'),
            'view' => Pages\ViewAiWorkflow::route('/{record}'),
        ];
    }
}
