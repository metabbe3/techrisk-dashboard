{{-- CustomProfilePage's view (owner 2026-11-20 + revisi 3): info block +
     contribution graph. The single-item navigation strip was dropped (owner:
     with only Profile it adds nothing), and the change-password form moved to
     the dedicated "Setting" page reachable from the profile dropdown. --}}
<x-filament-panels::page>
    <div class="tr-profile-stack">
        <section class="tr-profile-info">
            <x-filament-panels::avatar.user :user="auth()->user()" />
            <div class="tr-profile-info-text">
                <span class="tr-profile-info-name">{{ e(auth()->user()->name) }}</span>
                <span class="tr-profile-info-email">{{ e(auth()->user()->email) }}</span>
            </div>
        </section>

        <section class="tr-profile-graph">
            {{-- Owner 2026-11-23: the title is a breakdown, not a bare count. --}}
            <div class="tr-profile-graph-head">
                <h3 class="tr-profile-graph-title">
                    <span class="tr-graph-stat"><span class="tr-graph-stat-value">{{ $contributionGraph['total'] }}</span> {{ \Illuminate\Support\Str::plural('incident', $contributionGraph['total']) }} in {{ $activeYear }}</span>
                </h3>
                <div class="tr-graph-stats">
                    <span class="tr-graph-stat"><span class="tr-contrib-cell tr-contrib-t1"></span> In progress <strong>{{ $contributionGraph['tiers'][1] }}</strong></span>
                    <span class="tr-graph-stat"><span class="tr-contrib-cell tr-contrib-t2"></span> Monitoring actions <strong>{{ $contributionGraph['tiers'][2] }}</strong></span>
                    <span class="tr-graph-stat"><span class="tr-contrib-cell tr-contrib-t3"></span> Completed <strong>{{ $contributionGraph['tiers'][3] }}</strong></span>
                </div>
            </div>
            @include('filament.pages.profile-graph', ['weeks' => $contributionGraph['weeks'], 'activeYear' => $activeYear, 'years' => $years])
        </section>
    </div>
</x-filament-panels::page>
