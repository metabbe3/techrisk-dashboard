<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;

/**
 * The notifications bell row at the bottom of the sidebar (SIDEBAR_FOOTER).
 *
 * Shows the unread database-notification count (same filter Filament's
 * database notifications use: data->format = 'filament') and opens the
 * slide-over modal owned by App\Filament\Livewire\DatabaseNotifications by
 * dispatching the standard `open-modal {id: 'database-notifications'}` event.
 *
 * Polls every 30s — broadcasting is disabled, so polling is used, mirroring
 * Filament's own topbar bell behaviour. The count query is the same one
 * Filament's component runs (getUnreadNotificationsCount()).
 */
class SidebarNotificationBell extends Component
{
    public int $unreadCount = 0;

    public function render(): \Illuminate\Contracts\View\View
    {
        $user = filament()->auth()->user();

        $this->unreadCount = (int) ($user
            ? $user->notifications()
                ->where('data->format', 'filament')
                ->unread()
                ->count()
            : 0);

        return view('filament.sidebar.notifications-trigger');
    }
}
