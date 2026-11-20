{{-- CustomProfilePage's view (owner 2026-11-20, GitHub-style profile):
     profile-land shell marker + info + contribution graph + right sub-menu
     nav + the vendor password form block VERBATIM (the EditProfile::save()
     pipeline — session password_hash_web refresh — is pinned by tests). --}}
<x-filament-panels::page>
    <div class="tr-profile-land">
        <div class="tr-profile-shell">
            <div class="tr-profile-main">
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

                <section class="tr-profile-password">
                    <x-filament-panels::form id="form" wire:submit="save">
                        {{ $this->form }}

                        <x-filament-panels::form.actions
                            :actions="$this->getCachedFormActions()"
                            :full-width="$this->hasFullWidthFormActions()"
                        />
                    </x-filament-panels::form>
                </section>
            </div>

            <aside class="tr-profile-nav">
                <span class="tr-profile-nav-item tr-profile-nav-active">Profile</span>
                <a class="tr-profile-nav-item" href="{{ route('filament.admin.resources.incidents.index', ['tableFilters' => ['pic' => ['values' => [auth()->user()->id]]]]) }}">My Incident</a>
                <button type="button" class="tr-profile-nav-item" x-data @click="window.dispatchEvent(new CustomEvent('tr-open-appearance'))">Appearance</button>
            </aside>
        </div>

        {{-- The sidebar Alpine store persists its open state — a user who had
             it pinned lands on the profile page with the overlay already open.
             Force-close on arrival (one-shot poll, SIDEBAR_NAV_END pattern). --}}
        <script>
            if (! window.__trProfileLandSidebar) {
                window.__trProfileLandSidebar = true
                var trProfileCloseSidebar = (attempt) => {
                    const sidebar = window.Alpine?.store?.('sidebar')
                    if (sidebar) { sidebar.close(); return }
                    if (attempt > 100) return
                    setTimeout(() => trProfileCloseSidebar(attempt + 1), 50)
                }
                document.addEventListener('DOMContentLoaded', () => trProfileCloseSidebar(0))
            }
        </script>
    </div>
</x-filament-panels::page>
