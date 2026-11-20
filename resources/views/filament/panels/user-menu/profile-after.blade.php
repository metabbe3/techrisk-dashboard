{{-- USER_MENU_PROFILE_AFTER hook — rendered INSIDE the profile dropdown, right
     after the stock "Profile" item: "Setting" (the dedicated change-password
     page) + the inline Appearance expander (3 preview cards; owner
     2026-11-20, GitHub reference). "My Incident" was removed — the "Your
     active incidents" row above already links to the same PIC-filtered
     list. The stock icon theme-switcher below is CSS-hidden — this expander
     replaces it. --}}
<x-filament::dropdown.list>
    <x-filament::dropdown.list.item
        :href="\App\Filament\Pages\ProfileSettings::getUrl()"
        icon="heroicon-m-cog-6-tooth"
        tag="a"
    >
        Setting
    </x-filament::dropdown.list.item>
</x-filament::dropdown.list>

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
        <div class="tr-theme-cards" x-cloak x-show="open" x-collapse>
            <button type="button" class="tr-theme-card" :class="theme === 'light' && 'tr-theme-card-active'" @click="pick('light')">
                <span class="tr-theme-preview tr-theme-preview-light" aria-hidden="true"><span class="tr-theme-preview-bar"></span><span class="tr-theme-preview-line"></span><span class="tr-theme-preview-block"></span></span>
                <span class="tr-theme-card-label">Light</span>
                <span class="tr-theme-card-active-badge" :class="theme !== 'light' && 'tr-hidden'">Active</span>
            </button>
            <button type="button" class="tr-theme-card" :class="theme === 'dark' && 'tr-theme-card-active'" @click="pick('dark')">
                <span class="tr-theme-preview tr-theme-preview-dark" aria-hidden="true"><span class="tr-theme-preview-bar"></span><span class="tr-theme-preview-line"></span><span class="tr-theme-preview-block"></span></span>
                <span class="tr-theme-card-label">Dark</span>
                <span class="tr-theme-card-active-badge" :class="theme !== 'dark' && 'tr-hidden'">Active</span>
            </button>
            <button type="button" class="tr-theme-card" :class="theme === 'system' && 'tr-theme-card-active'" @click="pick('system')">
                <span class="tr-theme-preview tr-theme-preview-system" aria-hidden="true"><span class="tr-theme-preview-bar"></span><span class="tr-theme-preview-line"></span><span class="tr-theme-preview-block"></span></span>
                <span class="tr-theme-card-label">System</span>
                <span class="tr-theme-card-active-badge" :class="theme !== 'system' && 'tr-hidden'">Active</span>
            </button>
        </div>
    </div>
</x-filament::dropdown.list>
