<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Filament\Pages\NotionSettings;
use App\Services\Notion\NotionIssueSyncService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Runs the Notion sub-page sync and tells the human what happened. Shared by
 * the Notion Settings page ("Sync now") and the Issue list header button
 * ("Sync with Notion") so the two surfaces cannot drift — the failure
 * preview was once added to one surface only.
 *
 * Two modes (owner 2026-10-29): a normal sync imports only new Notion rows —
 * deleting an Issue from the dashboard is permanent. The "Full sync" flow
 * (owner 2026-10-30, redesign) first restores nothing by itself: it opens
 * the restore picker — the deleted issues a full sync WOULD bring back
 * (restorableIssues(), the same filter the run applies) — and imports only
 * the ones the operator ticks there (Select All included); unticked issues
 * stay deleted. The dry-run Preview button was removed in the same redesign
 * (owner 2026-10-30): the picker list IS the preview.
 */
class NotionSyncAction
{
    public static function run(bool $restoreDeleted = false, ?array $restorePageIds = null): void
    {
        $result = app(NotionIssueSyncService::class)->sync(
            restoreDeleted: $restoreDeleted,
            restorePageIds: $restorePageIds,
        );

        if ($result['status'] === 'ok') {
            Notification::make()->success()->title($result['message'])->send();

            return;
        }

        // The failed rows are the diagnostic the message refers to — show them.
        $body = $result['message'];

        if (filled($result['preview'])) {
            $body .= "\n\n".Str::limit($result['preview'], 1500);
        }

        Notification::make()->danger()->title('Sync failed')->body($body)->persistent()->send();
    }

    /**
     * The Sync-now modal's form (Notion Settings page only — the Issue-list
     * button is normal-sync-only per owner 2026-10-29). One schema, two
     * states driven by the page's $fullSyncPickerOpen flag (owner
     * 2026-10-30): step 1 shows the full-sync checkbox; clicking the
     * "Full sync" submit sets the flag and reads Notion — the same modal
     * then renders step 2, the restore picker below.
     */
    public static function form(): array
    {
        return [
            Checkbox::make('restoreDeleted')
                ->label('Full sync — restore deleted issues')
                ->helperText('Also re-import issues that were previously deleted from this dashboard — you pick which ones. A normal sync imports only new Notion entries.')
                ->live()
                ->visible(fn (NotionSettings $livewire): bool => ! $livewire->fullSyncPickerOpen),
            CheckboxList::make('restorePageIds')
                ->label('Deleted issues to restore')
                ->helperText('Only the ticked issues are restored — everything you leave unticked stays deleted, including duplicates deleted on purpose. New Notion rows import either way.')
                ->options(fn (NotionSettings $livewire): array => $livewire->fullSyncPickerOpen ? $livewire->restorableIssueOptions() : [])
                ->visible(fn (NotionSettings $livewire): bool => $livewire->fullSyncPickerOpen && $livewire->restorableIssueOptions() !== [])
                // Live: without it, ticking (or Select All) updates the state
                // but never re-renders the modal — the Import button's
                // disabled state is frozen at the last render and the
                // operator can never activate it (prod 2026-10-30).
                ->live()
                ->bulkToggleable()
                ->columnSpanFull(),
            Placeholder::make('restore_picker_empty')
                ->content(new HtmlString(
                    'No restorable deleted issues right now — either nothing was deleted, or Notion could not be reached to list them.'
                ))
                ->visible(fn (NotionSettings $livewire): bool => $livewire->fullSyncPickerOpen && $livewire->restorableIssueOptions() === [])
                ->columnSpanFull(),
        ];
    }
}
