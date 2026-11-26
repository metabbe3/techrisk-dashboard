<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Services\Doha\DohaSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Runs the DOHA ticket sync and tells the human what happened. Shared by the
 * DOHA Tickets list header, the ticket view page and the System Settings DOHA
 * tab so the surfaces cannot drift (the NotionSyncAction pattern). UI sync is
 * incremental only — the weekly full re-read is scheduled (Sunday 03:00).
 */
class DohaSyncAction
{
    public static function make(): Action
    {
        return Action::make('syncDoha')
            ->label('Sync now')
            ->icon('heroicon-m-arrow-path')
            ->action(fn () => self::run());
    }

    public static function run(): void
    {
        $result = app(DohaSyncService::class)->sync();

        if ($result['status'] === 'ok') {
            Notification::make()->success()->title('DOHA sync')->body($result['message'])->send();

            return;
        }

        Notification::make()->danger()->title('Sync failed')->body($result['message'])->persistent()->send();
    }
}
