{{-- USER_MENU_PROFILE_BEFORE hook — rendered INSIDE the profile dropdown, before
     the stock "Profile" item. GitHub-style "signed in as" header + the active-
     incident count (owner 2026-11-20). The count click and "My Incident" share
     ONE deep link (the incidents list with the pic filter pre-set). --}}
@php
    $user = filament()->auth()->user();
    $myIncidentsUrl = route('filament.admin.resources.incidents.index', [
        'tableFilters' => ['pic' => ['values' => [$user->id]]],
    ]);
    $activeCount = \App\Support\MyIncidents::activeCount($user);
@endphp

<x-filament::dropdown.list>
    <div class="tr-user-menu-head">
        <x-filament-panels::avatar.user :user="$user" />
        <div class="tr-user-menu-head-text">
            <span class="tr-user-menu-head-name" title="{{ e($user->name) }}">{{ e($user->name) }}</span>
            <span class="tr-user-menu-head-email" title="{{ e($user->email) }}">{{ e($user->email) }}</span>
        </div>
    </div>
</x-filament::dropdown.list>

<x-filament::dropdown.list>
    <x-filament::dropdown.list.item
        :href="$myIncidentsUrl"
        icon="heroicon-m-bell-alert"
        tag="a"
    >
        {{ trans_choice('Your active incident|Your active incidents', $activeCount) }} ({{ $activeCount }})
    </x-filament::dropdown.list.item>
</x-filament::dropdown.list>
