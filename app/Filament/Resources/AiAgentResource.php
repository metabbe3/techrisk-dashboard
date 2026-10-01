<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AiAgentFrequency;
use App\Filament\Resources\AiAgentResource\Pages;
use App\Models\AiAgent;
use App\Services\Ai\AiAgentDraftService;
use App\Services\Ai\AiAgentTemplates;
use App\Services\Ai\AiTextService;
use Cron\CronExpression;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AiAgentResource extends Resource
{
    protected static ?string $model = AiAgent::class;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Agents';

    protected static ?string $modelLabel = 'Agent';

    protected static ?string $pluralModelLabel = 'Agents';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationGroup = 'AI';

    public static function canViewAny(): bool
    {
        return auth()->check() && auth()->user()->can('manage api tokens');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Describe it in your own words')
                    ->description('Optional — AI drafts the whole form from one sentence; everything stays editable.')
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->schema([
                        Forms\Components\Textarea::make('draft_prompt')
                            ->label('What should this agent do?')
                            ->rows(3)
                            ->maxLength(2000)
                            ->dehydrated(false)
                            ->live()
                            ->placeholder('e.g. Every morning at 8:30, summarize critical incidents; a second agent reviews it and runs after that one')
                            ->hintActions([
                                Forms\Components\Actions\Action::make('draft_with_ai')
                                    ->label('Draft with AI')
                                    ->icon('heroicon-o-sparkles')
                                    ->color('warning')
                                    ->action(function (Forms\Set $set, Forms\Get $get): void {
                                        $userPrompt = trim((string) $get('draft_prompt'));

                                        if ($userPrompt === '') {
                                            Notification::make()
                                                ->warning()
                                                ->title('Nothing to draft from')
                                                ->body('Describe what the agent should do first, then click Draft with AI.')
                                                ->send();

                                            return;
                                        }

                                        try {
                                            $result = app(AiAgentDraftService::class)->draft(
                                                $userPrompt,
                                                null,
                                                AiAgent::query()->where('enabled', true)->pluck('name', 'id')->all(),
                                            );
                                        } catch (\Throwable) {
                                            Notification::make()
                                                ->danger()
                                                ->title('Draft failed')
                                                ->body('An unexpected error occurred. Please try again.')
                                                ->send();

                                            return;
                                        }

                                        if (! $result['success']) {
                                            Notification::make()
                                                ->danger()
                                                ->title('Draft failed')
                                                ->body($result['error'])
                                                ->send();

                                            return;
                                        }

                                        $draft = $result['agent'];
                                        // schedule_type first so the time/weekday fields un-hide before being filled.
                                        $set('schedule_type', $draft['schedule_type']);
                                        $set('run_time', $draft['run_time']);
                                        $set('run_weekday', $draft['run_weekday']);
                                        $set('name', $draft['name']);
                                        $set('description', $draft['description']);
                                        $set('instructions', $draft['instructions']);
                                        $set('include_context', $draft['include_context']);
                                        $set('expected_output', $draft['expected_output']);
                                        $set('require_json', $draft['require_json']);
                                        $set('depends_on_agent_id', $draft['depends_on_agent_id']);
                                        $set('reports_to_agent_id', $draft['reports_to_agent_id']);
                                        $set('include_memory', $draft['include_memory']);

                                        Notification::make()
                                            ->success()
                                            ->title('Draft filled')
                                            ->body('Review the fields and save when it looks right.')
                                            ->send();
                                    }),
                            ])
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Start from a template')
                    ->description('Optional — pre-fills the form; everything stays editable.')
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->schema([
                        Forms\Components\Select::make('template')
                            ->options(array_keys(AiAgentTemplates::all()))
                            ->placeholder('Start from scratch…')
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Forms\Set $set): void {
                                $template = $state ? AiAgentTemplates::all()[$state] ?? null : null;
                                if (! $template) {
                                    return;
                                }

                                $set('name', $template['name']);
                                $set('description', $template['description']);
                                $set('instructions', $template['instructions']);
                                $set('include_context', $template['include_context']);
                            })
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Agent')
                    ->description('v1 agents are text-only: instructions become the system prompt of a single generation. No code or tools are executed.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(120),
                        Forms\Components\TextInput::make('description')
                            ->maxLength(255)
                            ->columnSpan(2),
                        Forms\Components\Textarea::make('instructions')
                            ->required()
                            ->maxLength(20000)
                            ->rows(15)
                            ->extraInputAttributes(['style' => 'font-family: monospace'])
                            ->helperText('Sent as the system prompt on every run. Say what the agent should produce, e.g. "Summarize the week\'s incidents as a digest".')
                            ->columnSpanFull(),
                        Forms\Components\Select::make('model')
                            ->options(fn () => app(AiTextService::class)->getModelsForPicker())
                            ->searchable()
                            ->placeholder('Default (from AI Settings)')
                            ->helperText('Leave empty to use the default model configured in AI Settings. Models marked unhealthy by the last health check are hidden.'),
                        Forms\Components\Toggle::make('include_context')
                            ->label('Include dashboard context')
                            ->helperText('Append live statistics (this year\'s incident counts, MTTR, fund loss) to every run so the agent reasons over real data.'),
                    ])->columns(2),

                Forms\Components\Section::make('Teamwork')
                    ->description('Agents can hand off to each other, share a memory of lessons learned, and see the team structure.')
                    ->schema([
                        Forms\Components\Select::make('depends_on_agent_id')
                            ->label('Runs after')
                            ->options(fn (?AiAgent $record): array => AiAgent::query()
                                ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->placeholder('—')
                            ->helperText('When that agent completes successfully, this one is queued automatically.')
                            ->rule(fn (?AiAgent $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record): void {
                                if ($value && AiAgent::createsDependencyCycle('depends_on_agent_id', $value, $record?->id)) {
                                    $fail('That would create a circular agent chain.');
                                }
                            }),
                        Forms\Components\Select::make('reports_to_agent_id')
                            ->label('Reports to')
                            ->options(fn (?AiAgent $record): array => AiAgent::query()
                                ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->placeholder('—')
                            ->helperText('Shown in the organization block injected into runs so agents know the team structure.')
                            ->rule(fn (?AiAgent $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record): void {
                                if ($value && AiAgent::createsDependencyCycle('reports_to_agent_id', $value, $record?->id)) {
                                    $fail('That would create a circular reporting line.');
                                }
                            }),
                        Forms\Components\Toggle::make('include_memory')
                            ->label('Shared agent memory')
                            ->helperText('Inject recent lessons/outcomes/notes into every run and ask the agent to record new ones.')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Files & documents')
                    ->description('Extra reading material injected into every run — extracted at upload, never parsed at run time.')
                    ->schema([
                        Forms\Components\FileUpload::make('new_files')
                            ->label('Attach reference files')
                            ->multiple()
                            ->dehydrated(false)
                            ->live()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->acceptedFileTypes(['.pdf', '.docx', '.xlsx', '.txt', '.md', '.csv', '.json'])
                            ->maxSize(15360)
                            ->helperText('pdf, docx, xlsx, txt, md, csv, json — up to 15MB each. Attached immediately; extracted text is injected into every run.')
                            ->afterStateUpdated(function (?array $state, Forms\Set $set, ?AiAgent $record): void {
                                if (! $record || empty($state)) {
                                    return;
                                }

                                $files = collect($state)
                                    ->map(fn ($path) => \Illuminate\Support\Facades\Storage::disk('local')->path($path))
                                    ->filter()
                                    ->map(fn ($absolute) => new \Illuminate\Http\UploadedFile($absolute, basename($absolute), null, null, true))
                                    ->all();

                                $result = app(\App\Services\Ai\AiAgentFileService::class)->attachMany($record, $files);

                                if ($result['attached'] !== []) {
                                    Notification::make()->success()->title(count($result['attached']).' file(s) attached')->send();
                                }
                                foreach ($result['errors'] as $error) {
                                    Notification::make()->danger()->title('Attach failed')->body($error)->send();
                                }
                            })
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('include_documents')
                            ->label('Include investigation documents')
                            ->helperText('Inject the extracted text of the most recent incident investigation documents into every run.'),
                        Forms\Components\Toggle::make('include_corpus')
                            ->label('Include incident catalog (long-term incident memory)')
                            ->helperText('Inject one line per incident on record into every run, so the agent knows the full incident history.'),
                    ]),

                Forms\Components\Section::make('Output contract')
                    ->description('Tell the agent exactly what its output should look like — enforced on every run.')
                    ->schema([
                        Forms\Components\Textarea::make('expected_output')
                            ->label('Expected output')
                            ->rows(2)
                            ->maxLength(2000)
                            ->columnSpanFull()
                            ->placeholder('e.g. 3 bullets, each ≤ 20 words, end with a one-line risk verdict')
                            ->helperText('Appended to every run as the output contract. Keep it short and concrete.'),
                        Forms\Components\Toggle::make('require_json')
                            ->label('Must be valid JSON')
                            ->helperText('The run fails if the output is not valid JSON (one automatic repair retry is attempted).'),
                    ])->columns(2),

                Forms\Components\Section::make('Schedule')
                    ->schema([
                        Forms\Components\Select::make('schedule_type')
                            ->label('Runs')
                            ->options([
                                'manual' => 'Manually only',
                                'daily' => 'Every day',
                                'hourly' => 'Every hour',
                                'weekly' => 'Every week',
                                'custom' => 'Custom (cron expression)',
                            ])
                            ->default('manual')
                            ->live()
                            ->required(),
                        Forms\Components\TimePicker::make('run_time')
                            ->label('At')
                            ->default('09:00')
                            ->seconds(false)
                            ->visible(fn (Forms\Get $get): bool => in_array($get('schedule_type'), ['daily', 'weekly']))
                            ->required(fn (Forms\Get $get): bool => in_array($get('schedule_type'), ['daily', 'weekly'])),
                        Forms\Components\Select::make('run_weekday')
                            ->label('On day')
                            ->options([
                                1 => 'Monday',
                                2 => 'Tuesday',
                                3 => 'Wednesday',
                                4 => 'Thursday',
                                5 => 'Friday',
                                6 => 'Saturday',
                                7 => 'Sunday',
                            ])
                            ->default(1)
                            ->visible(fn (Forms\Get $get): bool => $get('schedule_type') === 'weekly')
                            ->required(fn (Forms\Get $get): bool => $get('schedule_type') === 'weekly'),
                        Forms\Components\TextInput::make('cron_expression')
                            ->label('Cron expression')
                            ->placeholder('0 9 * * *  — daily at 09:00 (server timezone)')
                            ->visible(fn (Forms\Get $get): bool => $get('schedule_type') === 'custom')
                            ->rule(fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                                if (blank($value)) {
                                    return;
                                }

                                try {
                                    CronExpression::factory($value);
                                } catch (\Throwable) {
                                    $fail('Invalid cron expression.');
                                }
                            })
                            ->helperText('Standard 5-field cron, evaluated every minute in the app timezone.'),
                        Forms\Components\Toggle::make('enabled')
                            ->default(true)
                            ->helperText('Disabled agents keep their history but are never dispatched.')
                            ->columnSpanFull(),
                    ])->columns(3),

                Forms\Components\Section::make('Delivery')
                    ->schema([
                        Forms\Components\TextInput::make('notify_email')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Optional — email this address with the output of every run (or the error, if it fails).')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (AiAgent $record): string => (string) $record->description),
                Tables\Columns\TextColumn::make('model')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state): string => $state ?: 'Default')
                    ->placeholder('Default'),
                Tables\Columns\TextColumn::make('frequency')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof AiAgentFrequency ? $state->value : (string) $state)
                    ->color(fn ($state): string => ($state instanceof AiAgentFrequency ? $state : AiAgentFrequency::from($state))->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('next_run_at')
                    ->label('Next run')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('lastRun.status')
                    ->label('Last run')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\AiAgentRunStatus ? $state->value : (string) $state)
                    ->color(fn ($state): string => $state instanceof \App\Enums\AiAgentRunStatus ? $state->color() : 'gray')
                    ->placeholder('Never'),
                Tables\Columns\TextColumn::make('last_run_at')
                    ->label('Last dispatched')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('enabled')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('include_context')
                    ->label('Context')
                    ->boolean()
                    ->tooltip('Includes live dashboard context in every run')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('include_memory')
                    ->label('Memory')
                    ->boolean()
                    ->tooltip('Reads and writes the shared agent memory')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('include_corpus')
                    ->label('Corpus')
                    ->boolean()
                    ->tooltip('Sees the full incident catalog (long-term incident memory)')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('reportsTo.name')
                    ->label('Reports to')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('dependsOn.name')
                    ->label('Runs after')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('notify_email')
                    ->label('Emails to')
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('enabled'),
                Tables\Filters\SelectFilter::make('frequency')
                    ->options(AiAgentFrequency::options()),
            ])
            ->actions([
                Tables\Actions\Action::make('runNow')
                    ->label('Run Now')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Queue a run of this agent now?')
                    ->action(function (AiAgent $record): void {
                        $record->dispatchRun();

                        Notification::make()
                            ->success()
                            ->title('Run queued')
                            ->body("{$record->name} has been queued. Check Agent Runs in a moment.")
                            ->send();
                    }),
                Tables\Actions\Action::make('viewRuns')
                    ->label('View Runs')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->url(fn (AiAgent $record): string => AiAgentRunResource::getUrl('index', ['tableFilters' => ['agent' => ['value' => $record->id]]])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Translate the form-only schedule fields (schedule_type/run_time/run_weekday)
     * into what the model and dispatcher actually store: frequency Manual|Cron
     * plus a raw cron expression. Shared by Create and Edit pages.
     */
    public static function resolveScheduleData(array $data): array
    {
        $type = $data['schedule_type'] ?? 'manual';

        $data['frequency'] = $type === 'manual'
            ? AiAgentFrequency::Manual->value
            : AiAgentFrequency::Cron->value;

        if ($type === 'manual') {
            $data['cron_expression'] = null;
        } elseif ($type !== 'custom') {
            $data['cron_expression'] = AiAgent::cronFromPreset($type, $data['run_time'] ?? null, $data['run_weekday'] ?? null);
        }
        // custom: cron_expression passes through as entered (validated above).

        unset($data['schedule_type'], $data['run_time'], $data['run_weekday']);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiAgents::route('/'),
            'create' => Pages\CreateAiAgent::route('/create'),
            'edit' => Pages\EditAiAgent::route('/{record}/edit'),
        ];
    }
}
