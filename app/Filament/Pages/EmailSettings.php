<?php

declare(strict_types=1);
namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\MailSettings;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Email / Netcore settings. Hosts the global email kill-switch plus the
 * incident & fund-loss reminder toggles and cadence. Settings persist to the
 * generic Setting key/value store (cached).
 */
class EmailSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Email Settings';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 101;

    protected static string $view = 'filament.pages.email-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('manage api tokens');
    }

    public function mount(): void
    {
        $this->form->fill([
            'netcore_enabled' => Setting::get('netcore_enabled', true),
            'api_key' => (string) Setting::get('netcore_api_key', config('mail.mailers.netcore.api_key') ?? ''),
            'base_url' => Setting::get('netcore_base_url', config('mail.mailers.netcore.base_url')),
            'incident_not_done_reminder_enabled' => Setting::get('incident_not_done_reminder_enabled', true),
            'incident_not_done_reminder_days' => Setting::get('incident_not_done_reminder_days', 7),
            'fund_loss_reminder_enabled' => Setting::get('fund_loss_reminder_enabled', true),
            'reminder_remind_interval_days' => Setting::get('reminder_remind_interval_days', 7),
            'from_address' => Setting::get('mail_from_address', 'noreply-techrisk@dana.id'),
            'from_name' => Setting::get('mail_from_name', config('mail.from.name')),
        ]);
    }

    /**
     * Human-readable activation state for the status panel — replaces the
     * old "set MAIL_MAILER in .env" dead end (activation is runtime now).
     */
    public function netcoreStatus(): string
    {
        $enabled = (bool) Setting::get('netcore_enabled', true);
        $hasKey = filled(Setting::get('netcore_api_key', config('mail.mailers.netcore.api_key')));

        if (! $enabled) {
            return 'Suppressed — toggle is off, no email is sent';
        }

        if (! $hasKey) {
            return 'No API key — save an API key to activate sending';
        }

        return 'Active — email sends via Netcore';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Netcore Email')
                    ->description('Global email provider controls. Activation is dashboard-controlled — no .env editing needed.')
                    ->schema([
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (EmailSettings $livewire): string => $livewire->netcoreStatus())
                            ->columnSpanFull(),

                        Toggle::make('netcore_enabled')
                            ->label('Send email (global kill-switch)')
                            ->helperText('Off = all Netcore email is suppressed, including PIC/action-improvement notifications.')
                            ->columnSpanFull(),

                        TextInput::make('api_key')
                            ->label('API Key')
                            ->password()
                            ->revealable()
                            ->helperText('Leave as-is to keep the current key. Clear to remove. Overrides NETCORE_API_KEY in .env.')
                            ->columnSpanFull(),

                        TextInput::make('base_url')
                            ->label('API Base URL')
                            ->url()
                            ->helperText('Netcore endpoint base. Overrides NETCORE_BASE_URL in .env.'),

                        TextInput::make('from_address')
                            ->label('From Address')
                            ->email()
                            ->helperText('Must be an approved Netcore sender (e.g. noreply-techrisk@dana.id).'),

                        TextInput::make('from_name')
                            ->label('From Name')
                            ->maxLength(100),
                    ])->columns(2),

                Section::make('Incident Reminders')
                    ->description('Remind the PIC (and escalate to admins) when an incident is still open.')
                    ->schema([
                        Toggle::make('incident_not_done_reminder_enabled')
                            ->label('Not-done incident reminders'),

                        TextInput::make('incident_not_done_reminder_days')
                            ->label('Remind after (days open)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(7)
                            ->helperText('Remind when incident_status != Completed and the incident is at least this old.'),

                        TextInput::make('reminder_remind_interval_days')
                            ->label('Re-remind every (days)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(90)
                            ->default(7)
                            ->helperText('Throttle — don’t re-remind the same incident more often than this.'),
                    ])->columns(2),

                Section::make('Fund-Loss Reminders')
                    ->description('Remind when a fund loss is not settled (Confirmed loss / Potential recovery with outstanding amount).')
                    ->schema([
                        Toggle::make('fund_loss_reminder_enabled')
                            ->label('Unsettled fund-loss reminders')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::set('netcore_enabled', (bool) ($data['netcore_enabled'] ?? false));

        // The field shows the real key, so no sentinel dance: filled -> keep,
        // blank -> remove.
        if (filled($data['api_key'] ?? null)) {
            Setting::set('netcore_api_key', $data['api_key']);
        } else {
            Setting::set('netcore_api_key', null);
        }

        if (filled($data['base_url'] ?? null)) {
            Setting::set('netcore_base_url', $data['base_url']);
        }

        Setting::set('mail_from_address', filled($data['from_address'] ?? null) ? $data['from_address'] : null);
        Setting::set('mail_from_name', filled($data['from_name'] ?? null) ? $data['from_name'] : null);

        Setting::set('incident_not_done_reminder_enabled', (bool) ($data['incident_not_done_reminder_enabled'] ?? false));
        Setting::set('incident_not_done_reminder_days', (int) ($data['incident_not_done_reminder_days'] ?? 7));
        Setting::set('fund_loss_reminder_enabled', (bool) ($data['fund_loss_reminder_enabled'] ?? false));
        Setting::set('reminder_remind_interval_days', (int) ($data['reminder_remind_interval_days'] ?? 7));

        // Apply immediately so this request (and restarted queue workers) use
        // the new mailer/From without waiting for a cache TTL or redeploy.
        MailSettings::apply();
        Artisan::call('queue:restart');

        Notification::make()
            ->success()
            ->title('Email settings saved')
            ->body('Changes take effect immediately.')
            ->send();
    }
}
