<x-filament-panels::page>
    <div class="fi-section-header fi-section-header-has-description">
        <div class="fi-section-header-heading">
            <h2 class="fi-section-header-title">
                Notification Center
            </h2>
            <span class="fi-section-header-description">
                Manage your notifications - {{ $this->getUnreadCount() }} unread
            </span>
        </div>
    </div>

    <x-slot name="content">
        {{ \Filament\Support\Facades\FilamentView::renderHook('panels::page.before', []) }}
        {{ $this->table }}
        {{ \Filament\Support\Facades\FilamentView::renderHook('panels::page.after', []) }}
    </x-slot>
</x-filament-panels::page>
