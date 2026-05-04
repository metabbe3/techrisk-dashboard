<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Notification Center
        </x-slot>
        <x-slot name="description">
            Manage your notifications - {{ $this->getUnreadCount() }} unread
        </x-slot>

        {{ \Filament\Support\Facades\FilamentView::renderHook('panels::page.before', []) }}
        {{ $this->table }}
        {{ \Filament\Support\Facades\FilamentView::renderHook('panels::page.after', []) }}
    </x-filament::section>
</x-filament-panels::page>
