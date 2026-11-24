<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\DohaSyncAction;
use App\Filament\Actions\NotionSyncAction;
use App\Models\AiSetting;
use App\Models\ApiSetting;
use App\Models\Setting;
use App\Services\Ai\AiTextService;
use App\Services\Doha\DohaClient;
use App\Services\Doha\DohaSyncService;
use App\Services\InvestigationForm\InvestigationFormSchema;
use App\Services\InvestigationForm\InvestigationFormSettings;
use App\Services\Notion\NotionInvestigationService;
use App\Services\Notion\NotionIssueSyncService;
use App\Services\Notion\NotionMcpClient;
use App\Support\MailSettings;
use Filament\Actions\Action as HeaderAction;
use Filament\Actions\StaticAction;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The one System settings page (PROJ-025) — Email, API, Notion and AI
 * settings as tabs of a single form. Each tab's schema and save logic moved
 * verbatim from the former ApiSettings / EmailSettings / NotionSettings /
 * AiSettings pages; only colliding field names were renamed (the AI tab's
 * base_url/api_key -> ai_base_url/ai_api_key, the duplicate status
 * placeholders -> netcore_status / notion_status). Notion's watch/parent IDs
 * are edited through their own Change -> Save flows, never through save().
 */
class SystemSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = null; // icon dropped: member renders as a cluster tab

    // Owner 2026-11-23: the cluster tab reads "System Settings" (the cluster
    // itself is Settings — two identical labels side by side were confusing).
    protected static ?string $navigationLabel = 'System Settings';

    protected static ?string $cluster = \App\Filament\Clusters\SettingsCluster::class;

    protected static \Filament\Pages\SubNavigationPosition $subNavigationPosition = \Filament\Pages\SubNavigationPosition::Top;

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.system-settings';

    public ?array $data = [];

    // Owner 2026-10-30: the watch ID is never open for direct editing — a
    // "Change" button unlocks the field, Save/Cancel confirm or discard.
    public bool $editingWatchId = false;

    public bool $watchIdJustSaved = false;

    public ?string $watchIdError = null;

    // PROJ-018: the investigation parent ID uses the same Change → Save/Cancel
    // flow as the watch ID.
    public bool $editingInvestigationParentId = false;

    public bool $investigationParentIdJustSaved = false;

    public ?string $investigationParentIdError = null;

    // The full-sync restore picker's options (owner 2026-10-30), memoized:
    // each option is a Notion page lookup, and the picker's options closure
    // re-runs on every Livewire render while the modal is open — the memo
    // keeps that to one Notion fan-out per full-sync click instead of one
    // per checkbox tick. The full-sync click drops the memo so the list is
    // read fresh from Notion at that moment.
    public ?array $restorableIssueOptionsCache = null;

    // Owner 2026-10-30: the Sync-now modal is a two-step flow — step 1 asks
    // full sync vs. normal, step 2 (this flag) replaces the form with the
    // restore picker once "Full sync" has read Notion.
    public bool $fullSyncPickerOpen = false;

    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('manage api tokens');
    }

    public function mount(): void
    {
        $this->form->fill([
            // Email (Netcore + reminders + investigation form)
            'netcore_enabled' => Setting::get('netcore_enabled', true),
            'api_key' => (string) Setting::get('netcore_api_key', config('mail.mailers.netcore.api_key') ?? ''),
            'base_url' => Setting::get('netcore_base_url', config('mail.mailers.netcore.base_url')),
            'incident_not_done_reminder_enabled' => Setting::get('incident_not_done_reminder_enabled', true),
            'incident_not_done_reminder_days' => Setting::get('incident_not_done_reminder_days', 7),
            'status_update_reminder_enabled' => Setting::get('status_update_reminder_enabled', true),
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

            // API rate limits
            'rate_limit_incidents' => ApiSetting::get('rate_limit.incidents', 100),
            'rate_limit_reference' => ApiSetting::get('rate_limit.reference', 30),
            'rate_limit_actions' => ApiSetting::get('rate_limit.actions', 60),
            'rate_limit_ai_export' => ApiSetting::get('rate_limit.ai_export', 60),
            'rate_limit_agent' => ApiSetting::get('rate_limit.agent', 120),

            // Notion (IDs are edited through their own Change -> Save flows)
            'watch_page_id' => (string) Setting::get(NotionIssueSyncService::WATCH_KEY, ''),
            'investigation_parent_id' => (string) Setting::get(NotionInvestigationService::PARENT_KEY, ''),

            // AI provider
            'ai_base_url' => AiSetting::get('base_url', config('ai.base_url')),
            'ai_api_key' => filled(AiSetting::get('api_key')) ? '••••••••' : '',
            'default_model' => AiSetting::get('default_model', config('ai.default_model')),
            'timeout' => AiSetting::get('timeout', config('ai.timeout', 30)),
            'war_room_agent_timeout' => AiSetting::get('war_room_agent_timeout', config('ai.war_room.agent_timeout', 600)),
            'war_room_moderator_timeout' => AiSetting::get('war_room_moderator_timeout', config('ai.war_room.moderator_timeout', 600)),
            'rate_limit' => AiSetting::get('rate_limit', config('ai.rate_limit_per_minute', 10)),
            'models' => AiSetting::get('models', config('ai.models', [])),

            // DOHA fund-loss ticket mirror
            'doha_api_token' => app(DohaClient::class)->configured() ? '••••••••' : '',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            HeaderAction::make('connect')
                ->label($this->connectButtonLabel())
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('primary')
                ->url(route('mcp.oauth.notion.connect'))
                // ⚠ The live tab URL is `?tab=-notion-tab` (Filament's
                // persistTabInQueryString ids) — a strict `=== 'notion'`
                // comparison hid these buttons on every real tab click
                // (owner 2026-11-23: "ke mana perginya button connect/
                // disconnect"). str_contains covers the legacy value too.
                ->visible(fn (): bool => str_contains((string) request()->query('tab'), 'notion')),

            HeaderAction::make('disconnect')
                ->label('Disconnect')
                ->icon('heroicon-m-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => str_contains((string) request()->query('tab'), 'notion')
                    && app(NotionMcpClient::class)->connected())
                ->action(function (): void {
                    app(NotionMcpClient::class)->disconnect();

                    Notification::make()->success()->title('Notion disconnected')->send();
                }),
        ];
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
                Tabs::make('Settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        $this->emailTab(),
                        $this->apiTab(),
                        $this->notionTab(),
                        $this->aiTab(),
                        $this->dohaTab(),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    private function emailTab(): Tab
    {
        return Tab::make('Email')
            ->icon('heroicon-o-envelope')
            ->schema([
                Section::make('Netcore Email')
                    ->description('Global email provider controls. Activation is dashboard-controlled — no .env editing needed.')
                    ->schema([
                        Placeholder::make('netcore_status')
                            ->label('Status')
                            ->content(fn (SystemSettings $livewire): string => $livewire->netcoreStatus())
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
                                    ->action(fn (SystemSettings $livewire) => $livewire->sendTestEmail())
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

                        Toggle::make('status_update_reminder_enabled')
                            ->label('Stale status-update reminders')
                            ->helperText('Email the PIC when an incident has no status update and is older than 6 days, or the last update is unsettled and older than 6 days.')
                            ->columnSpanFull(),

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
                            ->content(new HtmlString(
                                'Request: '.collect(InvestigationFormSettings::PLACEHOLDERS)->map(fn (string $p): string => '{'.$p.'}')->implode(', ')
                                .'<br>Reminder: the same plus {days_left}'
                            ))
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    private function apiTab(): Tab
    {
        return Tab::make('API')
            ->icon('heroicon-o-adjustments-horizontal')
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
                        TextInput::make('rate_limit_agent')
                            ->label('Agent schedules / runs (DingTalk agent)')
                            ->numeric()->minValue(1)->step(1)->suffix('req/min')->default(120),
                    ])
                    ->columns(2),
            ]);
    }

    private function notionTab(): Tab
    {
        return Tab::make('Notion')
            ->icon('heroicon-o-link')
            ->schema([
                Section::make('Connection')
                    ->description("One workspace-wide connection to Notion's hosted MCP server (OAuth 2.1). The connecting admin's Notion account determines the accessible workspace.")
                    ->schema([
                        Placeholder::make('notion_status')
                            ->label('Status')
                            ->content(fn (SystemSettings $livewire): HtmlString => $livewire->connectionStatusHtml())
                            ->columnSpanFull(),

                        Placeholder::make('last_sync')
                            ->label('Last sub-page sync')
                            ->content(fn (SystemSettings $livewire): string => $livewire->lastSyncStatus())
                            ->columnSpanFull(),
                    ]),

                Section::make('Sync issue from Notion')
                    ->description('Runs at 09:00, 12:00, 15:00, and 18:00 (WIB): new sub-pages import as Issues (dedupe by Notion page ID and title, against Issues and Incidents). Deleting an imported issue from the dashboard is permanent — only "Sync now" with "Full sync" brings deleted issues back, and only the ones you pick there.')
                    ->schema([
                        // Owner 2026-10-30: the ID changes only through the
                        // explicit Change → Save flow below, never by typing
                        // straight into the field.
                        Placeholder::make('watch_page_id_display')
                            ->label('Notion page or database ID')
                            ->content(function (SystemSettings $livewire): HtmlString {
                                $current = (string) ($livewire->data['watch_page_id'] ?? '');

                                $value = filled($current)
                                    ? '<span class="font-mono text-sm">'.e($current).'</span>'
                                    : '<span class="text-gray-500 dark:text-gray-400">Not set</span>';

                                $badge = $livewire->watchIdJustSaved
                                    ? ' <span class="font-semibold text-success-600 dark:text-success-400">✓ Saved</span>'
                                    : '';

                                return new HtmlString($value.$badge);
                            })
                            ->columnSpanFull(),

                        TextInput::make('watch_id_input')
                            ->label('New Notion page or database ID')
                            ->placeholder('e.g. 1f2e3d4c5b6a... or the page URL')
                            ->helperText('Copy the ID or URL from the Notion page/database to watch. Accepts a full URL or a raw ID.')
                            ->live()
                            ->visible(fn (SystemSettings $livewire): bool => $livewire->editingWatchId)
                            ->columnSpanFull(),

                        Placeholder::make('watch_id_error')
                            ->content(fn (SystemSettings $livewire): ?HtmlString => filled($livewire->watchIdError) ? new HtmlString(
                                '<span class="text-sm font-semibold text-danger-600 dark:text-danger-400">'.e($livewire->watchIdError).'</span>'
                            ) : null)
                            ->visible(fn (SystemSettings $livewire): bool => filled($livewire->watchIdError))
                            ->columnSpanFull(),

                        // Owner 2026-10-30: Change and Sync now sit on ONE
                        // horizontal line — while editing, Save/Cancel take
                        // Change's slot in the same row.
                        Actions::make([
                            Action::make('changeWatchId')
                                ->label('Change')
                                ->icon('heroicon-o-pencil-square')
                                ->visible(fn (SystemSettings $livewire): bool => ! $livewire->editingWatchId)
                                ->action(function (SystemSettings $livewire): void {
                                    $livewire->editingWatchId = true;
                                    $livewire->watchIdJustSaved = false;
                                    $livewire->watchIdError = null;
                                    $livewire->data['watch_id_input'] = (string) ($livewire->data['watch_page_id'] ?? '');
                                }),

                            Action::make('saveWatchId')
                                ->label('Save')
                                ->icon('heroicon-m-check')
                                ->color('primary')
                                ->visible(fn (SystemSettings $livewire): bool => $livewire->editingWatchId)
                                // Story 3: disabled while the input is empty or
                                // unchanged; the ->action() guard enforces it
                                // server-side too.
                                ->disabled(function (SystemSettings $livewire, Get $get): bool {
                                    $extracted = $livewire->normalizePageId(trim((string) ($get('watch_id_input') ?? '')));

                                    return blank($extracted) || $extracted === (string) ($get('watch_page_id') ?? '');
                                })
                                ->action(fn (SystemSettings $livewire) => $livewire->saveWatchId()),

                            Action::make('cancelWatchId')
                                ->label('Cancel')
                                ->color('gray')
                                ->visible(fn (SystemSettings $livewire): bool => $livewire->editingWatchId)
                                ->action(function (SystemSettings $livewire): void {
                                    $livewire->editingWatchId = false;
                                    $livewire->watchIdError = null;
                                    $livewire->data['watch_id_input'] = null;
                                }),

                            Action::make('syncNow')
                                ->label('Sync now')
                                ->icon('heroicon-m-arrow-path')
                                ->form(NotionSyncAction::form())
                                // A closed modal re-opens at step 1 (Cancel/X
                                // leave the step-2 flag behind).
                                ->mountUsing(function (Form $form, SystemSettings $livewire): void {
                                    $livewire->fullSyncPickerOpen = false;
                                    $form->fill();
                                })
                                // Owner 2026-10-30: the submit is "Sync" — and
                                // becomes "Full sync" once the checkbox is
                                // ticked; at step 2 it is "Import", greyed out
                                // until at least one issue is selected.
                                ->modalSubmitActionLabel(function (SystemSettings $livewire): string {
                                    if ($livewire->fullSyncPickerOpen) {
                                        return 'Import';
                                    }

                                    return ((bool) ($livewire->mountedFormComponentActionsData[0]['restoreDeleted'] ?? false))
                                        ? 'Full sync'
                                        : 'Sync';
                                })
                                // The param MUST be named $action — that is the
                                // injected name for the real submit button;
                                // any other name resolves a throwaway instance
                                // from the container.
                                ->modalSubmitAction(function (StaticAction $action, SystemSettings $livewire): void {
                                    if ($livewire->fullSyncPickerOpen) {
                                        $action->disabled(blank($livewire->mountedFormComponentActionsData[0]['restorePageIds'] ?? []));
                                    }
                                })
                                ->action(function (array $data, SystemSettings $livewire, Action $action): void {
                                    if ($livewire->fullSyncPickerOpen) {
                                        // Step 2: import ONLY the picked issues —
                                        // the disabled Import button keeps an
                                        // empty pick from reaching here anyway.
                                        NotionSyncAction::run(
                                            restoreDeleted: true,
                                            restorePageIds: array_values((array) ($data['restorePageIds'] ?? [])),
                                        );

                                        return;
                                    }

                                    // blank() is wrong here — booleans are never
                                    // blank (blank(false) === false), so an
                                    // unticked checkbox would fall through to the
                                    // full-sync branch.
                                    if (! ($data['restoreDeleted'] ?? false)) {
                                        NotionSyncAction::run(restoreDeleted: false, restorePageIds: null);

                                        return; // normal sync — the tail unmount closes the modal
                                    }

                                    // Step 1 → 2: "Full sync" clicked. Read Notion
                                    // NOW (drop the options memo so the list is
                                    // fresh), then stay open as the picker —
                                    // halt() keeps the modal mounted; the tail
                                    // unmount of a normal completion would close it.
                                    $livewire->restorableIssueOptionsCache = null;
                                    $livewire->fullSyncPickerOpen = true;
                                    $action->halt();
                                }),
                        ]),
                    ]),

                // PROJ-018: where Start Investigation creates its child pages.
                Section::make('Notion investigation pages')
                    ->description('Start Investigation creates a child page under this Notion parent page. Investigation pages are independent of the sub-page sync above.')
                    ->schema([
                        Placeholder::make('investigation_parent_id_display')
                            ->label('Investigation page ID')
                            ->content(function (SystemSettings $livewire): HtmlString {
                                $current = (string) ($livewire->data['investigation_parent_id'] ?? '');

                                $value = filled($current)
                                    ? '<span class="font-mono text-sm">'.e($current).'</span>'
                                    : '<span class="text-gray-500 dark:text-gray-400">Not set</span>';

                                $badge = $livewire->investigationParentIdJustSaved
                                    ? ' <span class="font-semibold text-success-600 dark:text-success-400">✓ Saved</span>'
                                    : '';

                                return new HtmlString($value.$badge);
                            })
                            ->columnSpanFull(),

                        TextInput::make('investigation_parent_id_input')
                            ->label('New investigation parent page ID')
                            ->placeholder('e.g. 1f2e3d4c5b6a... or the page URL')
                            ->helperText('Copy the ID or URL of the Notion page (or database data source, collection://…) the investigation pages are created under. Accepts a full URL or a raw ID.')
                            ->live()
                            ->visible(fn (SystemSettings $livewire): bool => $livewire->editingInvestigationParentId)
                            ->columnSpanFull(),

                        Placeholder::make('investigation_parent_id_error')
                            ->content(fn (SystemSettings $livewire): ?HtmlString => filled($livewire->investigationParentIdError) ? new HtmlString(
                                '<span class="text-sm font-semibold text-danger-600 dark:text-danger-400">'.e($livewire->investigationParentIdError).'</span>'
                            ) : null)
                            ->visible(fn (SystemSettings $livewire): bool => filled($livewire->investigationParentIdError))
                            ->columnSpanFull(),

                        Actions::make([
                            Action::make('changeInvestigationParentId')
                                ->label('Change')
                                ->icon('heroicon-o-pencil-square')
                                ->visible(fn (SystemSettings $livewire): bool => ! $livewire->editingInvestigationParentId)
                                ->action(function (SystemSettings $livewire): void {
                                    $livewire->editingInvestigationParentId = true;
                                    $livewire->investigationParentIdJustSaved = false;
                                    $livewire->investigationParentIdError = null;
                                    $livewire->data['investigation_parent_id_input'] = (string) ($livewire->data['investigation_parent_id'] ?? '');
                                }),

                            Action::make('saveInvestigationParentId')
                                ->label('Save')
                                ->icon('heroicon-m-check')
                                ->color('primary')
                                ->visible(fn (SystemSettings $livewire): bool => $livewire->editingInvestigationParentId)
                                ->disabled(function (SystemSettings $livewire, Get $get): bool {
                                    $extracted = $livewire->normalizePageId(trim((string) ($get('investigation_parent_id_input') ?? '')));

                                    return blank($extracted) || $extracted === (string) ($get('investigation_parent_id') ?? '');
                                })
                                ->action(fn (SystemSettings $livewire) => $livewire->saveInvestigationParentId()),

                            Action::make('cancelInvestigationParentId')
                                ->label('Cancel')
                                ->color('gray')
                                ->visible(fn (SystemSettings $livewire): bool => $livewire->editingInvestigationParentId)
                                ->action(function (SystemSettings $livewire): void {
                                    $livewire->editingInvestigationParentId = false;
                                    $livewire->investigationParentIdError = null;
                                    $livewire->data['investigation_parent_id_input'] = null;
                                }),
                        ]),
                    ]),
            ]);
    }

    private function aiTab(): Tab
    {
        return Tab::make('AI')
            ->icon('heroicon-o-cpu-chip')
            ->schema([
                Section::make('API Configuration')
                    ->description('Configure the connection to your AI service provider.')
                    ->schema([
                        TextInput::make('ai_base_url')
                            ->label('API Base URL')
                            ->url()
                            ->helperText('The base URL will be appended with /chat/completions. Change the buildUrl() method in AiTextService if your API uses a different path.')
                            ->columnSpan(2),

                        TextInput::make('ai_api_key')
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
                            Action::make('check_model_health')
                                ->label('Check Model Health')
                                ->icon('heroicon-o-signal')
                                ->color('gray')
                                ->action(function () {
                                    $results = app(AiTextService::class)->checkModelsHealth();

                                    if (empty($results)) {
                                        Notification::make()
                                            ->warning()
                                            ->title('Health check skipped')
                                            ->body('No models configured, or the gateway URL/key is not set.')
                                            ->send();

                                        return;
                                    }

                                    $counts = collect($results)->groupBy(fn ($r) => $r['status'])->map->count();

                                    Notification::make()
                                        ->success()
                                        ->title('Model health checked')
                                        ->body($counts->map(fn ($count, $status) => "{$count} {$status}")->implode(', '))
                                        ->send();
                                }),
                        ]),
                    ]),
            ]);
    }

    /**
     * DOHA fund-loss ticket mirror (owner 2026-11-23): the service-account
     * token (encrypted at rest, ≤90-day lifetime — rotate in DOHA and paste
     * the new one here), the sync status, and a manual Sync now.
     */
    private function dohaTab(): Tab
    {
        return Tab::make('DOHA')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Section::make('Connection')
                    ->description('Read-only mirror of DOHA fund-loss tickets (the TS ticketing tool). The sync runs every 15 minutes plus a weekly full re-read on Sunday 03:00.')
                    ->schema([
                        Placeholder::make('doha_status')
                            ->label('Status')
                            ->content(fn (SystemSettings $livewire): HtmlString => new HtmlString(
                                app(DohaClient::class)->configured()
                                    ? '<span class="font-bold text-success-600 dark:text-success-400">Active — token configured</span>'
                                    : '<span class="font-semibold text-danger-600 dark:text-danger-400">No token set — paste the DOHA service-account token to activate the sync</span>'
                            ))
                            ->columnSpanFull(),

                        TextInput::make('doha_api_token')
                            ->label('DOHA API token')
                            ->password()
                            ->revealable()
                            ->helperText('Service-account token from the DOHA admin, scope incidents.view, read-only, ≤90-day lifetime. Leave as-is to keep the current token — clear to remove; rotate in DOHA and update it here.')
                            ->columnSpanFull(),

                        Placeholder::make('doha_last_sync')
                            ->label('Last DOHA sync')
                            ->content(fn (SystemSettings $livewire): string => $livewire->dohaLastSyncStatus())
                            ->columnSpanFull(),

                        Actions::make([
                            Action::make('doha_sync_now')
                                ->label('Sync now')
                                ->icon('heroicon-o-arrow-path')
                                ->color('primary')
                                ->action(fn () => DohaSyncAction::run()),
                        ]),
                    ]),
            ]);
    }

    /** Header-less hook for tests (and keyboard-friendly re-runs). */
    public function runDohaSync(): void
    {
        DohaSyncAction::run();
    }

    public function dohaLastSyncStatus(): string
    {
        $at = Setting::get(DohaSyncService::LAST_SYNC_AT_KEY);

        if (blank($at)) {
            return 'Not synced yet — the scheduler runs every 15 minutes once the token is set.';
        }

        return sprintf('%s — %s', \Illuminate\Support\Carbon::parse($at)->format('Y-m-d H:i'), Setting::get(DohaSyncService::LAST_SYNC_STATUS_KEY, ''));
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
        if (! MailSettings::enabled() || config('mail.default') !== 'netcore') {
            Notification::make()
                ->danger()
                ->title('Test email not sent')
                ->body(! MailSettings::enabled()
                    ? 'The global kill-switch is ON — all email is suppressed. Turn on "Send email" above first.'
                    : $this->netcoreStatus())
                ->persistent()
                ->send();

            return;
        }

        try {
            Mail::send('emails.notification', [
                'headline' => 'Test email',
                'intro' => 'If you received this, Netcore delivery works — and this is exactly what portal emails look like.',
                'actionText' => 'Go to Dashboard',
                'actionUrl' => route('filament.admin.pages.dashboard'),
            ], function ($message) use ($to): void {
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

        // Investigation form: reject unknown {placeholders} BEFORE persisting
        // anything — a typo'd token would ship verbatim in every email, and
        // the all-or-nothing guarantee must hold across every tab.
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

        // --- Email ---
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
        Setting::set('status_update_reminder_enabled', (bool) ($data['status_update_reminder_enabled'] ?? false));
        Setting::set('fund_loss_reminder_enabled', (bool) ($data['fund_loss_reminder_enabled'] ?? false));
        Setting::set('reminder_remind_interval_days', (int) ($data['reminder_remind_interval_days'] ?? 7));

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

        // --- API rate limits ---
        ApiSetting::set('rate_limit.incidents', (int) ($data['rate_limit_incidents'] ?? 100));
        ApiSetting::set('rate_limit.reference', (int) ($data['rate_limit_reference'] ?? 30));
        ApiSetting::set('rate_limit.actions', (int) ($data['rate_limit_actions'] ?? 60));
        ApiSetting::set('rate_limit.ai_export', (int) ($data['rate_limit_ai_export'] ?? 60));
        ApiSetting::set('rate_limit.agent', (int) ($data['rate_limit_agent'] ?? 120));

        // --- AI provider ---
        AiSetting::set('base_url', $data['ai_base_url'] ?? null);

        if (filled($data['ai_api_key'] ?? null) && $data['ai_api_key'] !== '••••••••') {
            AiSetting::set('api_key', $data['ai_api_key']);
        } elseif (($data['ai_api_key'] ?? '') === '') {
            AiSetting::set('api_key', null);
        }

        // --- DOHA fund-loss mirror (external-service credential — encrypted
        // at rest like the Notion token; sentinel keeps the current one) ---
        if (filled($data['doha_api_token'] ?? null) && $data['doha_api_token'] !== '••••••••') {
            Setting::set(DohaClient::TOKEN_KEY, Crypt::encryptString($data['doha_api_token']));
        } elseif (($data['doha_api_token'] ?? '') === '') {
            Setting::set(DohaClient::TOKEN_KEY, null);
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

        // Apply immediately so this request (and restarted queue workers) use
        // the new mailer/From without waiting for a cache TTL or redeploy.
        MailSettings::apply();
        Artisan::call('queue:restart');

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->body('Changes take effect immediately.')
            ->send();
    }

    /**
     * Owner 2026-10-29: "Connect" before any connection, "Reconnect" once a
     * token exists — a failed refresh keeps the stored token, so the problem
     * states stay on "Reconnect".
     */
    public function connectButtonLabel(): string
    {
        return app(NotionMcpClient::class)->connected() ? 'Reconnect' : 'Connect';
    }

    /**
     * The four connection states, machine-readable — connectionStatus()
     * turns each into its message and connectionStatusHtml() adds the
     * color coding (green+bold healthy, red broken, amber renewal pending).
     */
    public function connectionState(): string
    {
        $client = app(NotionMcpClient::class);

        if (! $client->connected()) {
            return 'not_connected';
        }

        if ($client->needsReconnect()) {
            return 'reconnect_required';
        }

        $expiresAt = $client->tokenExpiresAt();

        if ($expiresAt !== null && $expiresAt <= time()) {
            return 'renewal_pending';
        }

        return 'connected';
    }

    public function connectionStatus(): string
    {
        $client = app(NotionMcpClient::class);

        if ($this->connectionState() === 'not_connected') {
            return 'Not connected — AI chat Notion tools and the sub-page sync are inactive.';
        }

        if ($this->connectionState() === 'reconnect_required') {
            return 'Reconnect required — the token could not be renewed (the refresh token may have been revoked in Notion). AI chat Notion tools and the sub-page sync are failing until an admin clicks Connect Notion.';
        }

        $expiresAt = $client->tokenExpiresAt();

        // Expired and not yet successfully re-newed — do not claim renewal.
        if ($this->connectionState() === 'renewal_pending') {
            return 'Connected — token expired '.now()->setTimestamp((int) $expiresAt)->diffForHumans().'; renewal pending. If calls keep failing, click Connect Notion.';
        }

        $expiry = $expiresAt
            ? ' Access token expires '.now()->setTimestamp((int) $expiresAt)->diffForHumans().' (auto-renews).'
            : '';

        return 'Connected — AI chat can search, read, and write Notion.'.$expiry;
    }

    public function connectionStatusHtml(): HtmlString
    {
        $classes = match ($this->connectionState()) {
            'not_connected', 'reconnect_required' => 'font-semibold text-danger-600',
            'renewal_pending' => 'font-semibold text-warning-600',
            default => 'font-bold text-success-600',
        };

        return new HtmlString('<span class="'.e($classes).'">'.e($this->connectionStatus()).'</span>');
    }

    public function lastSyncStatus(): string
    {
        $at = Setting::get(NotionIssueSyncService::LAST_SYNC_AT_KEY);

        if (blank($at)) {
            return 'Not synced yet — the scheduler runs at 09:00, 12:00, 15:00, and 18:00 (WIB) once connected.';
        }

        return sprintf('%s — %s', \Illuminate\Support\Carbon::parse($at)->format('Y-m-d H:i'), Setting::get(NotionIssueSyncService::LAST_SYNC_STATUS_KEY, ''));
    }

    /**
     * The full-sync restore picker's options, computed once per page load —
     * each option is a Notion page lookup, and the modal's schema closures
     * re-run on every Livewire render.
     *
     * @return array<string, string> Notion page ID => "{title} ({date}, {severity})"
     */
    public function restorableIssueOptions(): array
    {
        return $this->restorableIssueOptionsCache ??= app(NotionIssueSyncService::class)->restorableIssues();
    }

    /**
     * The watch-ID editor's Save (owner 2026-10-30). The Change button
     * unlocked the field; this validates, persists, and locks it again. An
     * invalid ID keeps edit mode with the input intact and shows the inline
     * error; a persisted value locks the field with a ✓ Saved badge.
     */
    public function saveWatchId(): void
    {
        $input = trim((string) ($this->data['watch_id_input'] ?? ''));
        $extracted = $this->normalizePageId($input);
        $current = (string) ($this->data['watch_page_id'] ?? '');

        if (blank($extracted) || $extracted === $current) {
            return; // disabled states — nothing to do
        }

        if (! preg_match('/^[a-f0-9]{32}$/i', str_replace('-', '', $extracted))) {
            $this->watchIdError = 'That value does not contain a Notion page or database ID — paste the page URL or its 32-character ID.';

            return; // edit mode + input preserved
        }

        try {
            Setting::set(NotionIssueSyncService::WATCH_KEY, $extracted);
        } catch (\Throwable $e) {
            report($e);
            $this->watchIdError = 'Could not save the new ID — please try again.';

            return; // edit mode + input preserved
        }

        $this->data['watch_page_id'] = $extracted;
        $this->data['watch_id_input'] = null;
        $this->editingWatchId = false;
        $this->watchIdError = null;
        $this->watchIdJustSaved = true;

        Notification::make()->success()->title('Notion settings saved')->send();
    }

    /**
     * PROJ-018: the investigation parent-ID editor's Save — same flow as
     * saveWatchId(), writing the investigation PARENT_KEY instead.
     */
    public function saveInvestigationParentId(): void
    {
        $input = trim((string) ($this->data['investigation_parent_id_input'] ?? ''));
        $current = (string) ($this->data['investigation_parent_id'] ?? '');

        // PROJ-018: a database data source (collection://…) is a valid parent
        // too — the created pages become its rows. Stored verbatim (prefix +
        // ID as pasted) so the service can route it as parent.data_source_id.
        $isDataSource = str_contains($input, 'collection://');
        $extracted = $this->normalizePageId($input);

        if ($isDataSource) {
            $extracted = 'collection://'.$extracted;
        }

        if (blank($extracted) || $extracted === $current) {
            return; // disabled states — nothing to do
        }

        if (! preg_match('/^(collection:\/\/)?[a-f0-9]{32}$/i', str_replace('-', '', $extracted))) {
            $this->investigationParentIdError = 'That value does not contain a Notion page or database ID — paste the page URL or its 32-character ID.';

            return; // edit mode + input preserved
        }

        try {
            Setting::set(NotionInvestigationService::PARENT_KEY, $extracted);
        } catch (\Throwable $e) {
            report($e);
            $this->investigationParentIdError = 'Could not save the new ID — please try again.';

            return; // edit mode + input preserved
        }

        $this->data['investigation_parent_id'] = $extracted;
        $this->data['investigation_parent_id_input'] = null;
        $this->editingInvestigationParentId = false;
        $this->investigationParentIdError = null;
        $this->investigationParentIdJustSaved = true;

        Notification::make()->success()->title('Notion settings saved')->send();
    }

    /**
     * Accept a full Notion URL, a bare ID, or a dashed UUID — the ID is
     * stored as pasted (dashes kept, owner 2026-11-04); only extracted out
     * of URLs. Both shapes Notion itself produces are recognized.
     */
    public function normalizePageId(string $value): string
    {
        if (preg_match('/([a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', trim($value), $m)) {
            return $m[1];
        }

        return trim($value);
    }
}
