{{-- Notifications bell row in the sidebar footer. Rendered by
     App\Livewire\SidebarNotificationBell (30s polling keeps the badge fresh).
     Opens the slide-over modal owned by
     App\Filament\Livewire\DatabaseNotifications (mounted at panels::body.end)
     via the standard open-modal browser event. --}}
<div wire:poll.30s>
    <button
        type="button"
        x-data="{}"
        x-on:click="$dispatch('open-modal', { id: 'database-notifications' })"
        class="sidebar-account-row group cursor-pointer border-0 bg-transparent p-0 text-start"
        title="{{ __('filament-panels::layout.actions.open_database_notifications.label') }}"
        aria-label="{{ __('filament-panels::layout.actions.open_database_notifications.label') }}{{ $unreadCount > 0 ? ' ('.$unreadCount.' unread)' : '' }}"
    >
        <span class="sidebar-account-icon relative grid place-items-center">
            <x-filament::icon icon="heroicon-o-bell" />
            @if ($unreadCount > 0)
                <span class="sidebar-account-badge" aria-hidden="true">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
            @endif
        </span>

        <span class="sidebar-account-label">{{ __('filament-panels::layout.actions.open_database_notifications.label') }}</span>
    </button>
</div>
