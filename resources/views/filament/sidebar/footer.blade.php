{{-- Sidebar footer (panels::sidebar.footer): notifications bell + account.
     The account block is a Filament dropdown (same component the vendor
     user-menu uses) with a full "avatar + name + email" trigger row — app.css
     (.sidebar-account-user) styles it. Items mirror the vendor user-menu for
     this panel: Profile (CustomProfilePage) + Logout (POST form to Filament's
     logout route — a plain link would 405 on the POST-only route). --}}

@php
    $user = filament()->auth()->user();
    $profileUrl = filament()->getProfileUrl();
    // Same label the vendor user-menu uses for the profile item: the profile
    // page's own label, falling back to the user name.
    $profileLabel = $profileUrl
        ? (\Filament\Facades\Filament::getProfilePage()::getLabel() ?? filament()->getUserName($user))
        : null;
@endphp

<div class="fi-sidebar-footer">
    @if ($user)
        <div class="sidebar-account-block">
            @livewire('sidebar-notification-bell')
        </div>

        <div class="sidebar-account-block sidebar-account-user">
            <x-filament::dropdown class="fi-user-menu" placement="top-end">
                <x-slot name="trigger">
                    <button
                        type="button"
                        class="sidebar-account-trigger"
                        aria-label="{{ __('filament-panels::layout.actions.open_user_menu.label') }}"
                    >
                        <x-filament-panels::avatar.user :user="$user" />

                        <span class="sidebar-account-meta">
                            <span class="sidebar-account-name">{{ filament()->getUserName($user) }}</span>
                            <span class="sidebar-account-email">{{ $user->email }}</span>
                        </span>

                        <x-filament::icon icon="heroicon-m-ellipsis-vertical" class="sidebar-account-icon" />
                    </button>
                </x-slot>

                @if ($profileUrl)
                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item
                            :href="$profileUrl"
                            icon="heroicon-m-user-circle"
                            tag="a"
                        >
                            {{ $profileLabel ?? __('Profile') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                @endif

                {{-- Light / dark / system switcher — same component and gate the
                     vendor user-menu uses (it was lost when the menu moved here). --}}
                @if (filament()->hasDarkMode() && (! filament()->hasDarkModeForced()))
                    <x-filament::dropdown.list>
                        <x-filament-panels::theme-switcher />
                    </x-filament::dropdown.list>
                @endif

                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item
                        :action="filament()->getLogoutUrl()"
                        icon="heroicon-m-arrow-left-on-rectangle"
                        method="post"
                        tag="form"
                    >
                        {{ __('filament-panels::layout.actions.logout.label') }}
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        </div>
    @endif
</div>
