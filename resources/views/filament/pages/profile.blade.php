{{-- CustomProfilePage's view (owner 2026-11-20, GitHub-style profile;
     revisi 2): a top navigation strip (Profile only — Appearance lives in the
     topbar dropdown), info block, contribution graph, and the change-password
     form revealed behind a button. The form block stays byte-identical to the
     vendor view, so the EditProfile::save() pipeline (session
     password_hash_web refresh) is untouched. The master sidebar stays DEFAULT
     on this page (profile-land shell dropped). --}}
<x-filament-panels::page>
    <nav class="tr-profile-tabs" aria-label="Profile sections">
        <span class="tr-profile-tab tr-profile-tab-active" aria-current="page">Profile</span>
    </nav>

    <div class="tr-profile-stack">
        <section class="tr-profile-info">
            <x-filament-panels::avatar.user :user="auth()->user()" />
            <div class="tr-profile-info-text">
                <span class="tr-profile-info-name">{{ e(auth()->user()->name) }}</span>
                <span class="tr-profile-info-email">{{ e(auth()->user()->email) }}</span>
            </div>
        </section>

        <section class="tr-profile-graph">
            <h3 class="tr-profile-graph-title">{{ \App\Support\MyIncidents::activeCount(auth()->user()) }} active {{ \Illuminate\Support\Str::plural('incident', \App\Support\MyIncidents::activeCount(auth()->user())) }} in the last 12 months</h3>
            @include('filament.pages.profile-graph', ['weeks' => $contributionGraph['weeks']])
        </section>

        <section class="tr-profile-password" x-data="{ open: false }">
            <x-filament::button type="button" color="gray" icon="heroicon-m-key" x-on:click="open = ! open">
                Change password
            </x-filament::button>

            <div class="tr-profile-password-ctn" x-cloak x-show="open" x-collapse>
                <x-filament-panels::form id="form" wire:submit="save">
                    {{ $this->form }}

                    <x-filament-panels::form.actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="$this->hasFullWidthFormActions()"
                    />
                </x-filament-panels::form>
            </div>
        </section>
    </div>
</x-filament-panels::page>
