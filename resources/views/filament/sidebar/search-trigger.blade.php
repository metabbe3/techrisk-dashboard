{{-- Command palette trigger row at the top of the sidebar (SIDEBAR_NAV_START).
     Dispatches the wire-elements/spotlight toggle event the same way its own
     Cmd/Ctrl+K binding does. The kbd hint is macOS-aware via Alpine. --}}
<button
    type="button"
    x-data="{ isMac: /^Mac/i.test(navigator.platform || navigator.userAgent) }"
    x-on:click="$dispatch('toggle-spotlight')"
    class="sidebar-search-row group cursor-pointer border-0 bg-transparent p-0 text-start"
    title="Search (Ctrl/Cmd + K)"
>
    <span class="sidebar-account-icon grid place-items-center">
        <x-filament::icon icon="heroicon-m-magnifying-glass" />
    </span>

    <span class="sidebar-account-label">Search…</span>

    <span class="sidebar-search-kbd" x-show="isMac">⌘K</span>
    <span class="sidebar-search-kbd" x-show="!isMac">Ctrl K</span>
</button>
