<?php

namespace App\Filament\Pages;

use App\Models\AiSetting;
use App\Services\Ai\AiTextService;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class AiSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'AI Settings';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.ai-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('manage api tokens');
    }

    public function mount(): void
    {
        $this->form->fill([
            'base_url' => AiSetting::get('base_url', config('ai.base_url')),
            'api_key' => filled(AiSetting::get('api_key')) ? '••••••••' : '',
            'default_model' => AiSetting::get('default_model', config('ai.default_model')),
            'timeout' => AiSetting::get('timeout', config('ai.timeout', 30)),
            'war_room_agent_timeout' => AiSetting::get('war_room_agent_timeout', config('ai.war_room.agent_timeout', 600)),
            'war_room_moderator_timeout' => AiSetting::get('war_room_moderator_timeout', config('ai.war_room.moderator_timeout', 600)),
            'rate_limit' => AiSetting::get('rate_limit', config('ai.rate_limit_per_minute', 10)),
            'models' => AiSetting::get('models', config('ai.models', [])),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('API Configuration')
                    ->description('Configure the connection to your AI service provider.')
                    ->schema([
                        TextInput::make('base_url')
                            ->label('API Base URL')
                            ->url()
                            ->helperText('The base URL will be appended with /chat/completions. Change the buildUrl() method in AiTextService if your API uses a different path.')
                            ->columnSpan(2),

                        TextInput::make('api_key')
                            ->label('API Key')
                            ->password()
                            ->revealable()
                            ->helperText('Leave as-is to keep the current key. Clear to remove.')
                            ->columnSpan(2),

                        TextInput::make('timeout')
                            ->label('Timeout (seconds)')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(600)
                            ->default(30)
                            ->helperText('General AI timeout for chat and text enhancements.'),

                        TextInput::make('war_room_agent_timeout')
                            ->label('AI Retrospective Agent Timeout (seconds)')
                            ->numeric()
                            ->minValue(30)
                            ->maxValue(1800)
                            ->default(600)
                            ->helperText('Per-agent timeout in AI Retrospective. Increase if agents timeout with HTTP 504 (the AI gateway may also need its timeout increased).'),

                        TextInput::make('war_room_moderator_timeout')
                            ->label('AI Retrospective Moderator Timeout (seconds)')
                            ->numeric()
                            ->minValue(30)
                            ->maxValue(1800)
                            ->default(600)
                            ->helperText('Report synthesis timeout. Can be higher than agent timeout for complex reports.'),

                        TextInput::make('rate_limit')
                            ->label('Rate Limit (per user per minute)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(10),
                    ])
                    ->columns(2),

                Section::make('AI Models')
                    ->description('Configure the AI models available for text enhancement.')
                    ->schema([
                        KeyValue::make('models')
                            ->keyLabel('Model ID')
                            ->valueLabel('Display Name')
                            ->addActionLabel('Add model')
                            ->helperText('Enter the model ID exactly as your API expects it (e.g., "gpt-4", "claude-3-sonnet").')
                            ->columnSpanFull(),

                        Select::make('default_model')
                            ->label('Default Model')
                            ->options(fn ($get) => collect($get('models') ?? [])
                                ->mapWithKeys(fn ($name, $id) => [$id => $name]))
                            ->helperText('This model is pre-selected when users click the AI enhance button.')
                            ->columnSpanFull(),

                        Actions::make([
                            Action::make('sync_models')
                                ->label('Sync from Gateway')
                                ->icon('heroicon-o-arrow-path')
                                ->color('primary')
                                ->action(function () {
                                    $models = app(AiTextService::class)->fetchModelsFromGateway();

                                    if (empty($models)) {
                                        Notification::make()
                                            ->warning()
                                            ->title('Sync failed')
                                            ->body('Could not fetch models. Check your API URL and key.')
                                            ->send();

                                        return;
                                    }

                                    $this->data['models'] = $models;

                                    AiSetting::set('models', $models);

                                    Notification::make()
                                        ->success()
                                        ->title('Models synced')
                                        ->body(count($models).' models fetched from the gateway.')
                                        ->send();
                                }),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        AiSetting::set('base_url', $data['base_url'] ?? null);

        if (filled($data['api_key'] ?? null) && $data['api_key'] !== '••••••••') {
            AiSetting::set('api_key', $data['api_key']);
        } elseif (($data['api_key'] ?? '') === '') {
            AiSetting::set('api_key', null);
        }

        if (filled($data['timeout'] ?? null)) {
            AiSetting::set('timeout', (int) $data['timeout']);
        }

        if (filled($data['war_room_agent_timeout'] ?? null)) {
            AiSetting::set('war_room_agent_timeout', (int) $data['war_room_agent_timeout']);
        }

        if (filled($data['war_room_moderator_timeout'] ?? null)) {
            AiSetting::set('war_room_moderator_timeout', (int) $data['war_room_moderator_timeout']);
        }

        if (filled($data['rate_limit'] ?? null)) {
            AiSetting::set('rate_limit', (int) $data['rate_limit']);
        }

        AiSetting::set('default_model', $data['default_model'] ?? 'default');
        AiSetting::set('models', $data['models'] ?? []);

        Notification::make()
            ->success()
            ->title('AI settings saved')
            ->body('Configuration updated. Changes take effect immediately.')
            ->send();
    }
}
