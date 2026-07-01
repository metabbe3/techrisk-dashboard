<?php

namespace App\Filament\Pages;

use App\Models\ApiSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ApiSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'API Settings';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 101;

    protected static string $view = 'filament.pages.api-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('manage api tokens');
    }

    public function mount(): void
    {
        $this->form->fill([
            'rate_limit_incidents' => ApiSetting::get('rate_limit.incidents', 100),
            'rate_limit_reference' => ApiSetting::get('rate_limit.reference', 30),
            'rate_limit_actions' => ApiSetting::get('rate_limit.actions', 60),
            'rate_limit_ai_export' => ApiSetting::get('rate_limit.ai_export', 60),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('API Rate Limits')
                    ->description('Per-user, per-minute request limits. Changes apply instantly — no redeploy needed.')
                    ->schema([
                        TextInput::make('rate_limit_incidents')
                            ->label('Incidents (list / show / by-no / markdown)')
                            ->numeric()->minValue(1)->step(1)->suffix('req/min')->default(100),
                        TextInput::make('rate_limit_reference')
                            ->label('Reference data (labels / types / categories / users)')
                            ->numeric()->minValue(1)->step(1)->suffix('req/min')->default(30),
                        TextInput::make('rate_limit_actions')
                            ->label('Action improvements')
                            ->numeric()->minValue(1)->step(1)->suffix('req/min')->default(60),
                        TextInput::make('rate_limit_ai_export')
                            ->label('AI export')
                            ->numeric()->minValue(1)->step(1)->suffix('req/min')->default(60),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        ApiSetting::set('rate_limit.incidents', (int) ($data['rate_limit_incidents'] ?? 100));
        ApiSetting::set('rate_limit.reference', (int) ($data['rate_limit_reference'] ?? 30));
        ApiSetting::set('rate_limit.actions', (int) ($data['rate_limit_actions'] ?? 60));
        ApiSetting::set('rate_limit.ai_export', (int) ($data['rate_limit_ai_export'] ?? 60));

        Notification::make()
            ->success()
            ->title('API settings saved')
            ->body('Rate limits updated.')
            ->send();
    }
}
