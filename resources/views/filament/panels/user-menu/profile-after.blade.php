{{-- USER_MENU_PROFILE_AFTER hook — rendered INSIDE the profile dropdown, right
     after the stock "Profile" item: the inline Appearance expander, then
     "Setting" (the dedicated change-password page) directly ABOVE the stock
     Sign out (owner 2026-11-21). "My Incident" was removed earlier — the
     "Your active incidents" row links to the same PIC-filtered list. The
     stock icon theme-switcher below is CSS-hidden — this expander replaces
     it. Owner 2026-11-21: the expander is three ICON buttons (sun / moon /
     monitor) — the preview cards are gone. --}}
<x-filament::dropdown.list>
    <div
        class="tr-theme-expander"
        x-data="{
            open: false,
            theme: localStorage.getItem('theme') || 'light',
            pick(next) {
                this.theme = next
                window.dispatchEvent(new CustomEvent('theme-changed', { detail: next }))
            },
        }"
    >
        <button type="button" class="tr-theme-toggle" @click="open = ! open" :aria-expanded="open">
            <x-filament::icon icon="heroicon-m-swatch" class="h-5 w-5 tr-theme-toggle-icon" />
            <span>Appearance</span>
            <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 tr-theme-chevron" x-bind:class="open && 'tr-rotate'" />
        </button>
        <div class="tr-theme-icons" x-cloak x-show="open" x-collapse>
            <button
                type="button"
                class="tr-theme-icon-btn"
                :class="theme === 'light' && 'tr-theme-icon-active'"
                @click="pick('light')"
                title="Light"
                aria-label="Light"
            >
                <x-filament::icon icon="heroicon-m-sun" class="h-5 w-5" />
            </button>
            <button
                type="button"
                class="tr-theme-icon-btn"
                :class="theme === 'dark' && 'tr-theme-icon-active'"
                @click="pick('dark')"
                title="Dark"
                aria-label="Dark"
            >
                <x-filament::icon icon="heroicon-m-moon" class="h-5 w-5" />
            </button>
            <button
                type="button"
                class="tr-theme-icon-btn"
                :class="theme === 'system' && 'tr-theme-icon-active'"
                @click="pick('system')"
                title="Sync with system"
                aria-label="Sync with system"
            >
                <x-filament::icon icon="heroicon-m-computer-desktop" class="h-5 w-5" />
            </button>
        </div>
    </div>
</x-filament::dropdown.list>

<x-filament::dropdown.list>
    <x-filament::dropdown.list.item
        :href="\App\Filament\Pages\ProfileSettings::getUrl()"
        icon="heroicon-m-cog-6-tooth"
        tag="a"
    >
        Setting
    </x-filament::dropdown.list.item>
</x-filament::dropdown.list>
