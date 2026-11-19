<?php

declare(strict_types=1);

namespace App\Filament\Livewire;

use Filament\Notifications\Livewire\DatabaseNotifications as BaseDatabaseNotifications;
use Illuminate\Contracts\View\View;

/**
 * Sidebar-chrome variant of Filament's database notifications component.
 *
 * The panel no longer mounts the topbar bell (->databaseNotifications(false)
 * in AdminPanelProvider): THIS component is mounted at panels::body.end via
 * resources/views/filament/sidebar/notifications-modal.blade.php, with the
 * trigger suppressed (getTrigger() → null) so it renders ONLY the slide-over
 * modal plus its 30s polling. The sidebar's bell row
 * (App\Livewire\SidebarNotificationBell) opens the modal by dispatching the
 * standard `open-modal {id: 'database-notifications'}` browser event.
 *
 * Why not inside the sidebar itself: the modal is position:fixed, and the
 * <aside> carries a `transform` (translate-x-0 / -translate-x-full) in every
 * state — a transformed ancestor re-anchors position:fixed descendants to the
 * aside, which would clip and misplace the modal. Mount at body level instead.
 */
class DatabaseNotifications extends BaseDatabaseNotifications
{
    public function getTrigger(): ?View
    {
        return null;
    }
}
