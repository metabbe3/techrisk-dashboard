<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarRoomAgentConfigResource\Pages;
use App\Models\WarRoomAgentConfig;
use App\Services\Ai\AiTextService;
use App\Services\Ai\ToolRegistryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WarRoomAgentConfigResource extends Resource
{
    protected static ?string $model = WarRoomAgentConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Discussion Agents';

    protected static ?string $modelLabel = 'Discussion Agent';

    protected static ?string $pluralModelLabel = 'Discussion Agents';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationGroup = 'Settings';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->can('manage incidents');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identity')
                    ->schema([
                        Forms\Components\TextInput::make('role_key')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->helperText('Unique identifier (e.g. sre, dev_be, security). Used internally.'),
                        Forms\Components\TextInput::make('display_name')
                            ->required()
                            ->maxLength(100)
                            ->columnSpan(2),
                        Forms\Components\Textarea::make('description')
                            ->maxLength(255)
                            ->rows(2)
                            ->columnSpan(3)
                            ->helperText('Short description shown in the Discussion Forum agent picker.'),
                    ])->columns(3),

                Forms\Components\Section::make('Appearance')
                    ->schema([
                        Forms\Components\TextInput::make('icon')
                            ->maxLength(50)
                            ->placeholder('heroicon-o-server')
                            ->helperText('Heroicon name (e.g. heroicon-o-server, heroicon-o-shield-check)'),
                        Forms\Components\Select::make('color')
                            ->options([
                                'blue' => 'Blue', 'indigo' => 'Indigo', 'purple' => 'Purple',
                                'green' => 'Green', 'teal' => 'Teal', 'cyan' => 'Cyan',
                                'red' => 'Red', 'orange' => 'Orange', 'amber' => 'Amber',
                                'pink' => 'Pink', 'fuchsia' => 'Fuchsia', 'rose' => 'Rose',
                                'sky' => 'Sky', 'violet' => 'Violet', 'yellow' => 'Yellow',
                                'emerald' => 'Emerald', 'gray' => 'Gray',
                            ]),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])->columns(3),

                Forms\Components\Section::make('Skills')
                    ->schema([
                        Forms\Components\Select::make('skills')
                            ->relationship('skillRecords', 'display_name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull()
                            ->helperText('Skills assigned to this agent. Full skill content (frameworks, procedures, methodology) is injected into the agent prompt during analysis.'),
                    ]),

                Forms\Components\Section::make('Tools')
                    ->schema([
                        Forms\Components\CheckboxList::make('enabled_tools')
                            ->label('Enabled Tools')
                            ->options(function () {
                                $toolRegistry = app(ToolRegistryService::class);

                                return collect($toolRegistry->getAllToolNames())
                                    ->mapWithKeys(fn ($name) => [$name => $name]);
                            })
                            ->columns(2)
                            ->columnSpanFull()
                            ->helperText('Tools this agent can use during analysis. Internal tools search incidents, get details, etc. External tools call configured APIs.'),
                    ]),

                Forms\Components\Section::make('System Prompt')
                    ->schema([
                        Forms\Components\Textarea::make('system_prompt')
                            ->required()
                            ->rows(12)
                            ->columnSpanFull()
                            ->helperText('The full system prompt sent to the AI. Include persona, skills, methodology, required analysis structure, and quality standards.')
                            ->hintActions([
                                Forms\Components\Actions\Action::make('enhance_prompt')
                                    ->label('AI Enhance')
                                    ->icon('heroicon-o-sparkles')
                                    ->color('warning')
                                    ->requiresConfirmation()
                                    ->modalHeading('AI Prompt Enhancement')
                                    ->modalDescription('Enhance the current draft prompt with comprehensive methodology, persona, analysis structure, and quality standards. This will replace the current prompt.')
                                    ->modalSubmitActionLabel('Enhance Prompt')
                                    ->action(function (Forms\Set $set, Forms\Get $get) {
                                        $currentPrompt = $get('system_prompt');

                                        if (blank($currentPrompt)) {
                                            Notification::make()
                                                ->warning()
                                                ->title('No prompt to enhance')
                                                ->body('Write a draft prompt first, then click AI Enhance.')
                                                ->send();

                                            return;
                                        }

                                        $aiService = app(AiTextService::class);

                                        $context = null;
                                        if (filled($get('display_name'))) {
                                            $context = "Agent: {$get('display_name')}";
                                        }
                                        if (filled($get('role_key'))) {
                                            $context = filled($context) ? "{$context}, Role: {$get('role_key')}" : "Role: {$get('role_key')}";
                                        }

                                        $result = $aiService->enhance(
                                            text: $currentPrompt,
                                            fieldType: 'agent_prompt_enhance',
                                            additionalPrompt: $context,
                                        );

                                        if ($result->success) {
                                            $set('system_prompt', $result->text);

                                            Notification::make()
                                                ->success()
                                                ->title('Prompt enhanced')
                                                ->body('Using model: '.($result->model ?? 'default'))
                                                ->send();
                                        } else {
                                            Notification::make()
                                                ->danger()
                                                ->title('Prompt enhancement failed')
                                                ->body($result->error)
                                                ->send();
                                        }
                                    }),
                            ]),
                    ]),

                Forms\Components\Section::make('Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('model_override')
                            ->maxLength(100)
                            ->placeholder('Leave empty for default model')
                            ->helperText('Override the AI model for this specific agent.'),
                        Forms\Components\Toggle::make('enable_web_search')
                            ->default(false)
                            ->helperText('Allow this agent to search the web for additional context.'),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->helperText('Inactive agents are hidden from the Discussion Forum picker.'),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role_key')
                    ->searchable()
                    ->badge()
                    ->color('gray'),
                Tables\Columns\ColorColumn::make('color')
                    ->label('Color'),
                Tables\Columns\IconColumn::make('icon')
                    ->label('Icon'),
                Tables\Columns\TextColumn::make('sort_order')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('enable_web_search')
                    ->boolean()
                    ->label('Web Search'),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarRoomAgentConfigs::route('/'),
            'create' => Pages\CreateWarRoomAgentConfig::route('/create'),
            'edit' => Pages\EditWarRoomAgentConfig::route('/{record}/edit'),
        ];
    }
}
