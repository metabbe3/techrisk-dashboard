<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Models\User;
use App\Services\Notion\NotionBody;
use App\Services\Notion\NotionInvestigationService;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;

/**
 * Start Investigation surfaces, one home so they cannot drift (the
 * NotionSyncAction precedent): the "…" kebab actions on both tables call
 * start(), the view pages' "Refresh from Notion" header action calls
 * refresh(), and both view pages render the same infolist section().
 */
class NotionInvestigationAction
{
    public static function start(Incident $record): void
    {
        $user = auth()->user();
        $result = app(NotionInvestigationService::class)->start($record, $user instanceof User ? $user : null);

        if ($result['status'] === 'ok') {
            // Round 10: continuing a soft-unlinked page is not a creation.
            $continued = ($result['continued'] ?? false) === true;

            Notification::make()->success()
                ->title($continued ? 'Investigation continued' : 'Investigation page created')
                ->body($result['message'])
                ->actions([
                    NotificationAction::make('open_in_notion')
                        ->label('Open in Notion')
                        ->url(app(NotionInvestigationService::class)->pageUrl($record->notion_investigation_page_id))
                        ->openUrlInNewTab(),
                ])
                ->send();

            return;
        }

        Notification::make()->danger()
            ->title('Investigation not started')
            ->body($result['message'])
            ->persistent()
            ->send();
    }

    public static function refresh(Incident $record): void
    {
        $result = app(NotionInvestigationService::class)->refresh($record);

        if ($result['status'] === 'ok') {
            Notification::make()->success()->title($result['message'])->send();

            return;
        }

        Notification::make()->danger()
            ->title('Refresh from Notion failed')
            ->body($result['message'])
            ->persistent()
            ->send();
    }

    public static function unlink(Incident $record): void
    {
        $result = app(NotionInvestigationService::class)->unlink($record);

        if ($result['status'] === 'ok') {
            Notification::make()->success()->title('Investigation link removed')->body($result['message'])->send();

            return;
        }

        Notification::make()->danger()
            ->title('Could not remove the investigation link')
            ->body($result['message'])
            ->persistent()
            ->send();
    }

    /**
     * Owner 2026-11-04 (round 8): opening the view page refreshes the stored
     * copy when it is stale — quietly: no toasts, a failure leaves the stored
     * copy and the page renders as-is. The calling page permission-gates this
     * (a write must not fire for a view-only visitor); the staleness window
     * keeps back-to-back opens from hammering Notion.
     */
    public static function autoRefresh(Incident $record): void
    {
        if (blank($record->notion_investigation_page_id)) {
            return;
        }

        // A known-deleted page must not be re-fetched on every open — the
        // flag was set by an earlier refresh that found the deleted envelope.
        if (filled($record->notion_investigation_deleted_at)) {
            return;
        }

        // Round 10: a deliberately unlinked record must not silently relink
        // on open — the operator picks the page back up themselves.
        if (filled($record->notion_investigation_unlinked_at)) {
            return;
        }

        if ($record->notion_investigation_synced_at?->gt(now()->subSeconds(60))) {
            return;
        }

        app(NotionInvestigationService::class)->refresh($record);
    }

    /** Round 9: from a flagged (deleted-in-Notion) page to a fresh one — the MCP server cannot restore from trash (live-probed 2026-11-04). */
    public static function reinvestigate(Incident $record): void
    {
        $user = auth()->user();
        $result = app(NotionInvestigationService::class)->reinvestigate($record, $user instanceof User ? $user : null);

        if ($result['status'] === 'ok') {
            Notification::make()->success()
                ->title($result['message'])
                ->actions([
                    NotificationAction::make('open_in_notion')
                        ->label('Open in Notion')
                        ->url(app(NotionInvestigationService::class)->pageUrl((string) $record->notion_investigation_page_id))
                        ->openUrlInNewTab(),
                ])
                ->send();

            return;
        }

        Notification::make()->danger()
            ->title('Could not re-investigate')
            ->body($result['message'])
            ->persistent()
            ->send();
    }

    /** The shared "Notion Investigation Pages" infolist section (view pages only — hidden until a page exists). */
    public static function section(): Section
    {
        return Section::make('Notion Investigation Pages')
            ->key('notion_investigation')
            ->icon('heroicon-o-magnifying-glass')
            ->iconColor('info')
            ->collapsible()
            ->columnSpanFull()
            ->schema([
                TextEntry::make('notion_investigation_markdown')
                    ->hiddenLabel()
                    ->markdown()
                    // Filament's HTML sanitizer drops Notion's envelope tags
                    // (toggle/table-of-contents/…) WITH their content —
                    // translate to standard HTML first (round 7).
                    ->formatStateUsing(fn ($state) => filled($state) ? NotionBody::toHtml((string) $state) : $state)
                    ->columnSpanFull(),
                // Round 10: a soft unlink clears the stored copy but keeps
                // the page — say so instead of rendering an empty body.
                TextEntry::make('notion_investigation_unlinked_at')
                    ->hiddenLabel()
                    ->state('Unlinked from Notion — the page link is kept. Relink investigation picks it back up.')
                    ->color('gray')
                    ->visible(fn (Incident $record) => filled($record->notion_investigation_unlinked_at))
                    ->columnSpanFull(),
                TextEntry::make('notion_investigation_synced_at')
                    // Owner 2026-11-04 (round 10): label and timestamp on ONE
                    // line, exactly as written: "Synced at 2026-11-04 14:41".
                    ->hiddenLabel()
                    ->formatStateUsing(fn ($state) => filled($state) ? 'Synced at '.$state->format('Y-m-d H:i') : null)
                    ->color('gray')
                    ->visible(fn (Incident $record) => filled($record->notion_investigation_synced_at))
                    ->columnSpanFull(),
            ])
            // Owner 2026-11-04 (round 8): all three buttons live side by side
            // in the section footer — the old header kebab is gone.
            // Round 9: when the page turned up deleted in Notion, the dead
            // buttons make way for Re-investigate (issues keep Unlink —
            // starting an investigation is incident-only).
            ->footerActions([
                InfolistAction::make('open_in_notion')
                    ->label('Open in Notion')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->button()
                    ->color('gray')
                    ->visible(fn (Incident $record) => blank($record->notion_investigation_deleted_at))
                    ->url(fn (Incident $record) => app(NotionInvestigationService::class)->pageUrl((string) $record->notion_investigation_page_id))
                    ->openUrlInNewTab(),
                InfolistAction::make('refresh_investigation')
                    // Round 10: after a soft unlink the same call re-links the
                    // kept page — say so instead of "Refresh from Notion".
                    ->label(fn (Incident $record) => filled($record->notion_investigation_unlinked_at)
                        ? 'Relink investigation'
                        : 'Refresh from Notion')
                    ->icon(fn (Incident $record) => filled($record->notion_investigation_unlinked_at)
                        ? 'heroicon-o-link'
                        : 'heroicon-o-arrow-path')
                    ->button()
                    ->color('info')
                    ->visible(fn (Incident $record) => blank($record->notion_investigation_deleted_at)
                        && (auth()->user()->can('manage incidents') || auth()->user()->can('manage issues')))
                    ->action(fn (Incident $record) => self::refresh($record)),
                InfolistAction::make('unlink_investigation')
                    ->label('Unlink investigation')
                    ->icon('heroicon-o-link-slash')
                    ->button()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Unlink investigation')
                    ->modalDescription('Clears the stored copy but keeps the Notion page — "Relink investigation" (or Start Investigation) picks the same page back up. The Notion page itself is not deleted.')
                    // Round 10: hidden once already unlinked — there is
                    // nothing left to unlink; Relink takes over.
                    ->visible(fn (Incident $record) => (blank($record->notion_investigation_deleted_at)
                        || $record->classification === IncidentClassification::Issue)
                        && blank($record->notion_investigation_unlinked_at)
                        && (auth()->user()->can('manage incidents') || auth()->user()->can('manage issues')))
                    ->action(fn (Incident $record) => self::unlink($record)),
                InfolistAction::make('reinvestigate_investigation')
                    ->label('Re-investigate')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->button()
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Re-investigate')
                    ->modalDescription('The linked Notion page was deleted. Notion\'s trash cannot be restored from here — a FRESH investigation page will be created (the deleted one stays in Notion\'s trash).')
                    ->visible(fn (Incident $record) => filled($record->notion_investigation_deleted_at)
                        && $record->classification !== IncidentClassification::Issue
                        && (auth()->user()->can('manage incidents') || auth()->user()->can('manage issues')))
                    ->action(fn (Incident $record) => self::reinvestigate($record)),
            ])
            ->visible(fn (Incident $record) => filled($record->notion_investigation_page_id));
    }
}
