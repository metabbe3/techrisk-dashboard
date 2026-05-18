<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgentToolResource\Pages;
use App\Models\AgentTool;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AgentToolResource extends Resource
{
    protected static ?string $model = AgentTool::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Agent Tools';

    protected static ?string $modelLabel = 'Agent Tool';

    protected static ?string $pluralModelLabel = 'Agent Tools';

    protected static ?int $navigationSort = 6;

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
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->regex('/^[a-z0-9_]+$/')
                            ->helperText('Unique identifier using lowercase letters, numbers, and underscores (e.g., jira_search, monitoring_check).')
                            ->dehydratedWhenHidden()
                            ->hiddenOn('edit'),
                        Forms\Components\TextInput::make('display_name')
                            ->required()
                            ->maxLength(100)
                            ->columnSpan(2),
                    ])->columns(3),

                Forms\Components\Section::make('LLM Configuration')
                    ->description('How the AI model sees and interacts with this tool.')
                    ->schema([
                        Forms\Components\Select::make('category')
                            ->required()
                            ->options([
                                'external_api' => 'External API',
                                'inhouse_api' => 'In-house API',
                            ])
                            ->default('external_api')
                            ->native(false),
                        Forms\Components\Textarea::make('description')
                            ->required()
                            ->rows(3)
                            ->columnSpan(2)
                            ->helperText('Description sent to the AI model. Explain what the tool does and when to use it.'),
                        Forms\Components\Textarea::make('parameters_schema')
                            ->required()
                            ->rows(8)
                            ->columnSpanFull()
                            ->helperText('OpenAI function-calling JSON Schema for parameters. Example: {"type":"object","properties":{"query":{"type":"string","description":"Search query"}},"required":["query"]}')
                            ->default('{"type":"object","properties":{},"required":[]}'),
                    ])->columns(3),

                Forms\Components\Section::make('Executor Configuration')
                    ->schema([
                        Forms\Components\Select::make('executor_type')
                            ->required()
                            ->options([
                                'http' => 'HTTP Request (REST API)',
                                'class' => 'Custom PHP Class',
                            ])
                            ->default('http')
                            ->live()
                            ->native(false),

                        // HTTP executor config
                        Forms\Components\Group::make()
                            ->visible(fn (Forms\Get $get) => $get('executor_type') === 'http')
                            ->schema([
                                Forms\Components\TextInput::make('http_config.url')
                                    ->label('API URL')
                                    ->required()
                                    ->placeholder('https://api.example.com/search')
                                    ->helperText('Use {param_name} placeholders for dynamic values from tool arguments.'),
                                Forms\Components\Select::make('http_config.method')
                                    ->label('HTTP Method')
                                    ->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'])
                                    ->default('GET')
                                    ->native(false),
                                Forms\Components\TextInput::make('http_config.timeout')
                                    ->label('Timeout (seconds)')
                                    ->numeric()
                                    ->default(10)
                                    ->minValue(1)
                                    ->maxValue(60),

                                Forms\Components\Section::make('Authentication')
                                    ->schema([
                                        Forms\Components\Select::make('http_config.auth_type')
                                            ->label('Auth Type')
                                            ->options([
                                                'none' => 'No Authentication',
                                                'bearer' => 'Bearer Token',
                                                'api_key' => 'API Key (Custom Header)',
                                                'basic' => 'Basic Auth',
                                            ])
                                            ->default('none')
                                            ->live()
                                            ->native(false),
                                        Forms\Components\TextInput::make('http_config.auth_key_env')
                                            ->label('Auth Key Environment Variable')
                                            ->placeholder('TOOL_MY_API_KEY')
                                            ->visible(fn (Forms\Get $get) => $get('http_config.auth_type') !== 'none')
                                            ->helperText('Name of the .env variable holding the API key/token.'),
                                        Forms\Components\TextInput::make('http_config.auth_header_name')
                                            ->label('Header Name')
                                            ->placeholder('X-API-Key')
                                            ->visible(fn (Forms\Get $get) => $get('http_config.auth_type') === 'api_key')
                                            ->helperText('HTTP header name for the API key.'),
                                    ])->columns(2),

                                Forms\Components\Section::make('Request Body')
                                    ->schema([
                                        Forms\Components\Textarea::make('http_config.body_template')
                                            ->label('Body Template (JSON)')
                                            ->rows(4)
                                            ->helperText('Use {param_name} placeholders. Only used for POST/PUT/PATCH. Example: {"query": "{query}", "limit": {limit}}')
                                            ->columnSpanFull(),
                                    ])->visible(fn (Forms\Get $get) => in_array($get('http_config.method'), ['POST', 'PUT', 'PATCH'])),

                                Forms\Components\Section::make('Response Mapping')
                                    ->schema([
                                        Forms\Components\TextInput::make('http_config.response_mapping')
                                            ->label('Response Path')
                                            ->placeholder('$.results[*].title')
                                            ->helperText('Dot-notation path to extract data from JSON response. Leave empty to return raw response.'),
                                    ]),
                            ])->columnSpanFull(),

                        // Class executor config
                        Forms\Components\Group::make()
                            ->visible(fn (Forms\Get $get) => $get('executor_type') === 'class')
                            ->schema([
                                Forms\Components\TextInput::make('custom_class')
                                    ->label('PHP Class')
                                    ->required()
                                    ->placeholder('App\Services\Ai\Tools\MyCustomTool')
                                    ->helperText('Fully-qualified class name. Must implement AgentToolInterface.')
                                    ->columnSpanFull(),
                            ])->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Status')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->helperText('Inactive tools are hidden from the AI agents.'),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'external_api' => 'info',
                        'inhouse_api' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'external_api' => 'External API',
                        'inhouse_api' => 'In-house API',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('executor_type')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'http' => 'HTTP',
                        'class' => 'PHP Class',
                        default => $state,
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->sortable(),
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
            'index' => Pages\ListAgentTools::route('/'),
            'create' => Pages\CreateAgentTool::route('/create'),
            'edit' => Pages\EditAgentTool::route('/{record}/edit'),
        ];
    }
}
