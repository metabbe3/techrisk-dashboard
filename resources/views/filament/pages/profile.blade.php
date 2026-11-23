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
            <h3 class="tr-profile-graph-title">{{ $contributionGraph['total'] }} {{ \Illuminate\Support\Str::plural('incident', $contributionGraph['total']) }} in {{ $activeYear }}</h3>
            @include('filament.pages.profile-graph', ['weeks' => $contributionGraph['weeks'], 'activeYear' => $activeYear, 'years' => $years])
        </section>
    </div>
</x-filament-panels::page>
