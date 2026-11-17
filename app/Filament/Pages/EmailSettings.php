<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\InvestigationForm\InvestigationFormSchema;
use App\Services\InvestigationForm\InvestigationFormSettings;
use App\Support\MailSettings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
            'investigation_form_expiry_days' => InvestigationFormSettings::expiryDays(),
            'investigation_form_max_recipients' => InvestigationFormSettings::maxRecipients(),
            'investigation_form_reminders_enabled' => InvestigationFormSettings::remindersEnabled(),
            'investigation_form_reminder_interval_days' => InvestigationFormSettings::reminderIntervalDays(),
            'investigation_form_final_reminder_days' => InvestigationFormSettings::finalReminderDays(),
            'investigation_form_prepare_list' => implode("\n", InvestigationFormSettings::prepareList()),
            'investigation_form_required_fields' => InvestigationFormSettings::requiredFields(),
            'investigation_form_request_subject' => InvestigationFormSettings::requestSubject(),
            'investigation_form_request_body' => InvestigationFormSettings::requestBody(),
            'investigation_form_reminder_subject' => InvestigationFormSettings::reminderSubject(),
            'investigation_form_reminder_body' => InvestigationFormSettings::reminderBody(),
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

                Section::make('Send Test Email')
                    ->description('Verify delivery end-to-end — the failure notification carries the exact provider error.')
                    ->schema([
                        TextInput::make('test_to')
                            ->label('Send test email to')
                            ->email()
                            ->suffixAction(
                                \Filament\Forms\Components\Actions\Action::make('sendTestEmail')
                                    ->icon('heroicon-m-paper-airplane')
                                    ->label('Send')
                                    ->action(fn (EmailSettings $livewire) => $livewire->sendTestEmail())
                            )
                            ->columnSpan(1),
                    ])->columns(2),

                Section::make('Recent Email Failures')
                    ->description('Last 5 queue jobs that exhausted retries with a Netcore error.')
                    ->schema([
                        Placeholder::make('recent_failures')
                            ->content(fn (): string => self::renderRecentFailures()),
                    ]),

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

                Section::make('Investigation Form')
                    ->description('Per-team investigation form links: emails, requirements, expiry and reminders.')
                    ->schema([
                        TextInput::make('investigation_form_expiry_days')
                            ->label('Link validity (days)')
                            ->numeric()->minValue(1)->maxValue(60)->default(14)
                            ->helperText('Each recipient link expires this many days after the request (or reissue).'),
                        TextInput::make('investigation_form_max_recipients')
                            ->label('Max recipients per team')
                            ->numeric()->minValue(1)->maxValue(50)->default(10),
                        Toggle::make('investigation_form_reminders_enabled')
                            ->label('Send auto reminders')
                            ->columnSpanFull(),
                        TextInput::make('investigation_form_reminder_interval_days')
                            ->label('Remind every (days)')
                            ->numeric()->minValue(1)->maxValue(14)->default(7)
                            ->helperText('Days since the last email before the next reminder.'),
                        TextInput::make('investigation_form_final_reminder_days')
                            ->label('Final reminder (days before expiry)')
                            ->numeric()->minValue(0)->maxValue(30)->default(2)
                            ->helperText('0 disables the final reminder.'),
                        Textarea::make('investigation_form_prepare_list')
                            ->label('What to prepare (one item per line)')
                            ->rows(4)
                            ->columnSpanFull(),
                        CheckboxList::make('investigation_form_required_fields')
                            ->label('Fields required to submit')
                            ->options(array_merge(
                                InvestigationFormSchema::flatFields(),
                                ['chronology' => 'Chronology (at least one row)'],
                            ))
                            ->columns(3)
                            ->bulkToggleable()
                            ->columnSpanFull()
                            ->helperText('Empty required fields block submit. The Statement of Truth is always required.'),
                        TextInput::make('investigation_form_request_subject')
                            ->label('Request email subject')
                            ->maxLength(200)
                            ->columnSpanFull(),
                        MarkdownEditor::make('investigation_form_request_body')
                            ->label('Request email body')
                            ->columnSpanFull(),
                        TextInput::make('investigation_form_reminder_subject')
                            ->label('Reminder email subject')
                            ->maxLength(200)
                            ->columnSpanFull(),
                        MarkdownEditor::make('investigation_form_reminder_body')
                            ->label('Reminder email body')
                            ->columnSpanFull(),
                        Placeholder::make('investigation_form_placeholder_help')
                            ->label('Placeholders')
                            ->content(new \Illuminate\Support\HtmlString(
                                'Request: '.collect(InvestigationFormSettings::PLACEHOLDERS)->map(fn (string $p): string => '{'.$p.'}')->implode(', ')
                                .'<br>Reminder: the same plus {days_left}'
                            ))
                            ->columnSpanFull(),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * Send a one-off test email through the active mailer. Failures surface
     * the exact provider response (owner request 2026-10-01: "if error can
     * you show the error logs?").
     */
    public function sendTestEmail(): void
    {
        $to = trim((string) ($this->data['test_to'] ?? ''));

        if ($to === '') {
            Notification::make()->danger()->title('Enter a recipient email address first.')->send();

            return;
        }

        // Suppressed / keyless leaves the app on the log mailer, where
        // Mail::raw "succeeds" by writing to laravel.log — a false green.
        if (config('mail.default') !== 'netcore') {
            Notification::make()
                ->danger()
                ->title('Test email not sent')
                ->body($this->netcoreStatus())
                ->persistent()
                ->send();

            return;
        }

        try {
            Mail::raw('This is a test email from the TechRisk Portal. If you received it, Netcore delivery works.', function ($message) use ($to): void {
                $message->to($to)->subject('TechRisk Portal — test email');
            });
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Test email failed')
                ->body(Str::limit($e->getMessage(), 500))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Test email sent')
            ->body("Delivered to {$to} — check the inbox (and spam folder).")
            ->send();
    }

    private static function renderRecentFailures(): string
    {
        try {
            $rows = DB::table('failed_jobs')
                ->where('exception', 'like', '%Netcore%')
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get();
        } catch (\Throwable) {
            return 'No failed jobs table.';
        }

        if ($rows->isEmpty()) {
            return 'No recent Netcore failures. 🎉';
        }

        return $rows->map(fn ($row): string => sprintf(
            '%s — %s — %s',
            $row->failed_at,
            $row->uuid,
            Str::limit(trim((string) $row->exception), 300),
        ))->implode("\n");
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

        // Investigation form: reject unknown {placeholders} before saving
        // anything (a typo'd token would ship verbatim in every email).
        $unknown = array_merge(
            InvestigationFormSettings::unknownPlaceholders((string) ($data['investigation_form_request_subject'] ?? ''), false),
            InvestigationFormSettings::unknownPlaceholders((string) ($data['investigation_form_request_body'] ?? ''), false),
            InvestigationFormSettings::unknownPlaceholders((string) ($data['investigation_form_reminder_subject'] ?? ''), true),
            InvestigationFormSettings::unknownPlaceholders((string) ($data['investigation_form_reminder_body'] ?? ''), true),
        );

        if ($unknown !== []) {
            Notification::make()
                ->danger()
                ->title('Unknown placeholders: '.implode(', ', $unknown))
                ->body('Allowed: '.implode(', ', array_merge(InvestigationFormSettings::PLACEHOLDERS, InvestigationFormSettings::REMINDER_PLACEHOLDERS)))
                ->persistent()
                ->send();

            return;
        }

        Setting::set('investigation_form_expiry_days', (int) ($data['investigation_form_expiry_days'] ?? 14));
        Setting::set('investigation_form_max_recipients', (int) ($data['investigation_form_max_recipients'] ?? 10));
        Setting::set('investigation_form_reminders_enabled', (bool) ($data['investigation_form_reminders_enabled'] ?? true));
        Setting::set('investigation_form_reminder_interval_days', (int) ($data['investigation_form_reminder_interval_days'] ?? 7));
        Setting::set('investigation_form_final_reminder_days', (int) ($data['investigation_form_final_reminder_days'] ?? 2));
        Setting::set('investigation_form_prepare_list', filled($data['investigation_form_prepare_list'] ?? null) ? $data['investigation_form_prepare_list'] : null);
        Setting::set('investigation_form_required_fields', array_values((array) ($data['investigation_form_required_fields'] ?? [])) ?: null);
        Setting::set('investigation_form_request_subject', filled($data['investigation_form_request_subject'] ?? null) ? $data['investigation_form_request_subject'] : null);
        Setting::set('investigation_form_request_body', filled($data['investigation_form_request_body'] ?? null) ? $data['investigation_form_request_body'] : null);
        Setting::set('investigation_form_reminder_subject', filled($data['investigation_form_reminder_subject'] ?? null) ? $data['investigation_form_reminder_subject'] : null);
        Setting::set('investigation_form_reminder_body', filled($data['investigation_form_reminder_body'] ?? null) ? $data['investigation_form_reminder_body'] : null);

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
