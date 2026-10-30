<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\NotionSyncAction;
use App\Models\Setting;
use App\Services\Notion\NotionIssueSyncService;
use App\Services\Notion\NotionMcpClient;
use Filament\Actions\Action as HeaderAction;
use Filament\Actions\StaticAction;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Notion connection settings: the workspace-wide MCP connection (OAuth via
 * laravel/mcp account linking) and the scheduled sub-page sync watch target.
 * The connection buttons live in the page header; the sync buttons live
 * inside the "Sync issue from Notion" section (owner 2026-10-29).
 */
class NotionSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Notion Settings';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 102;

    protected static string $view = 'filament.pages.notion-settings';

    public ?array $data = [];

    // Owner 2026-10-30: the watch ID is never open for direct editing — a
    // "Change" button unlocks the field, Save/Cancel confirm or discard.
    public bool $editingWatchId = false;

    public bool $watchIdJustSaved = false;

    public ?string $watchIdError = null;

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
            'watch_page_id' => (string) Setting::get(NotionIssueSyncService::WATCH_KEY, ''),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            HeaderAction::make('connect')
                ->label($this->connectButtonLabel())
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('primary')
                ->url(route('mcp.oauth.notion.connect')),

            HeaderAction::make('disconnect')
                ->label('Disconnect')
                ->icon('heroicon-m-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => app(NotionMcpClient::class)->connected())
                ->action(function (): void {
                    app(NotionMcpClient::class)->disconnect();

                    Notification::make()->success()->title('Notion disconnected')->send();
                }),
        ];
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

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Connection')
                    ->description("One workspace-wide connection to Notion's hosted MCP server (OAuth 2.1). The connecting admin's Notion account determines the accessible workspace.")
                    ->schema([
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (NotionSettings $livewire): HtmlString => $livewire->connectionStatusHtml())
                            ->columnSpanFull(),

                        Placeholder::make('last_sync')
                            ->label('Last sub-page sync')
                            ->content(fn (NotionSettings $livewire): string => $livewire->lastSyncStatus())
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
                            ->content(function (NotionSettings $livewire): HtmlString {
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
                            ->visible(fn (NotionSettings $livewire): bool => $livewire->editingWatchId)
                            ->columnSpanFull(),

                        Placeholder::make('watch_id_error')
                            ->content(fn (NotionSettings $livewire): ?HtmlString => filled($livewire->watchIdError) ? new HtmlString(
                                '<span class="text-sm font-semibold text-danger-600 dark:text-danger-400">'.e($livewire->watchIdError).'</span>'
                            ) : null)
                            ->visible(fn (NotionSettings $livewire): bool => filled($livewire->watchIdError))
                            ->columnSpanFull(),

                        // Owner 2026-10-30: Change and Sync now sit on ONE
                        // horizontal line — while editing, Save/Cancel take
                        // Change's slot in the same row.
                        Actions::make([
                            Action::make('changeWatchId')
                                ->label('Change')
                                ->icon('heroicon-o-pencil-square')
                                ->visible(fn (NotionSettings $livewire): bool => ! $livewire->editingWatchId)
                                ->action(function (NotionSettings $livewire): void {
                                    $livewire->editingWatchId = true;
                                    $livewire->watchIdJustSaved = false;
                                    $livewire->watchIdError = null;
                                    $livewire->data['watch_id_input'] = (string) ($livewire->data['watch_page_id'] ?? '');
                                }),

                            Action::make('saveWatchId')
                                ->label('Save')
                                ->icon('heroicon-m-check')
                                ->color('primary')
                                ->visible(fn (NotionSettings $livewire): bool => $livewire->editingWatchId)
                                // Story 3: disabled while the input is empty or
                                // unchanged; the ->action() guard enforces it
                                // server-side too.
                                ->disabled(function (NotionSettings $livewire, Get $get): bool {
                                    $extracted = $livewire->normalizePageId(trim((string) ($get('watch_id_input') ?? '')));

                                    return blank($extracted) || $extracted === (string) ($get('watch_page_id') ?? '');
                                })
                                ->action(fn (NotionSettings $livewire) => $livewire->saveWatchId()),

                            Action::make('cancelWatchId')
                                ->label('Cancel')
                                ->color('gray')
                                ->visible(fn (NotionSettings $livewire): bool => $livewire->editingWatchId)
                                ->action(function (NotionSettings $livewire): void {
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
                                ->mountUsing(function (Form $form, NotionSettings $livewire): void {
                                    $livewire->fullSyncPickerOpen = false;
                                    $form->fill();
                                })
                                // Owner 2026-10-30: the submit is "Sync" — and
                                // becomes "Full sync" once the checkbox is
                                // ticked; at step 2 it is "Import", greyed out
                                // until at least one issue is selected.
                                ->modalSubmitActionLabel(function (NotionSettings $livewire): string {
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
                                ->modalSubmitAction(function (StaticAction $action, NotionSettings $livewire): void {
                                    if ($livewire->fullSyncPickerOpen) {
                                        $action->disabled(blank($livewire->mountedFormComponentActionsData[0]['restorePageIds'] ?? []));
                                    }
                                })
                                ->action(function (array $data, NotionSettings $livewire, Action $action): void {
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
            ])
            ->statePath('data');
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

        if (! preg_match('/^[a-f0-9]{32}$/i', $extracted)) {
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
     * Accept a full Notion URL or a bare ID — store the bare ID either way.
     */
    public function normalizePageId(string $value): string
    {
        $value = trim($value);

        if (preg_match('/([a-f0-9]{32})/i', $value, $m)) {
            return $m[1];
        }

        return $value;
    }
}
