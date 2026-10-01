@php
    $inc = $entry->getRecord();
    $cards = $inc->activeSimilarCards();
@endphp

<x-filament::section>
    <x-slot name="heading">
        <div class="flex items-center gap-2">
            <x-filament::icon icon="heroicon-o-magnifying-glass-circle" class="w-5 h-5! text-primary-500!" />
            <span>Similar Incidents</span>
            @if(count($cards) > 0)
            <x-filament::badge color="primary">{{ count($cards) }} match{{ count($cards) !== 1 ? 'es' : '' }}</x-filament::badge>
            @endif
        </div>
    </x-slot>

    @if(count($cards) === 0)
        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
            <x-filament::icon icon="heroicon-o-information-circle" class="w-3.5 h-3.5! text-gray-400!" />
            <span>None detected yet — use Detect Similar above, or Find Similar on the edit page.</span>
        </div>
    @else
        <div class="space-y-3">
            @foreach($cards as $card)
                @php
                    $sevColor = match($card['severity'] ?? null) {
                        'P1' => 'danger', 'P2' => 'warning', 'P3' => 'info', 'P4' => 'primary',
                        'X1' => 'danger', 'X2' => 'warning', 'X3' => 'info', 'X4' => 'primary',
                        default => 'gray',
                    };
                    $isDeep = ($card['match_type'] ?? '') === 'deep';
                    $pct = (int) round((float) ($card['similarity'] ?? 0) * 100);
                @endphp
                <div class="flex items-start gap-3 p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-white/5">
                    <div class="flex-shrink-0 mt-0.5" title="AI similarity score — {{ $isDeep ? 'same root cause mechanism' : 'related theme, different cause' }}">
                        <x-filament::badge :color="$isDeep ? 'danger' : 'gray'" size="sm">
                            <span class="font-bold">{{ $pct }}%</span>
                        </x-filament::badge>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="{{ url('/admin/incidents/' . ($card['id'] ?? '')) }}" class="text-sm font-semibold text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 underline-offset-2 hover:underline">{{ $card['no'] ?? 'N/A' }}</a>
                            @if(($card['severity'] ?? null))
                            <x-filament::badge :color="$sevColor" size="sm">{{ $card['severity'] }}</x-filament::badge>
                            @endif
                            @if(($card['match_type'] ?? null))
                            <x-filament::badge :color="$isDeep ? 'danger' : 'gray'" size="sm">
                                <span class="flex items-center gap-1">
                                    <x-filament::icon :icon="$isDeep ? 'heroicon-o-link' : 'heroicon-o-squares-2x2'" class="w-3 h-3!" />
                                    {{ ucfirst($card['match_type']) }}
                                </span>
                            </x-filament::badge>
                            @endif
                            @if(($card['incident_status'] ?? null))
                            <x-filament::badge :color="$card['incident_status'] === 'Completed' ? 'success' : 'gray'" size="sm">{{ $card['incident_status'] }}</x-filament::badge>
                            @endif
                            @if(($card['incident_date'] ?? null))
                            <span class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                <x-filament::icon icon="heroicon-o-calendar" class="w-3 h-3! text-gray-400!" />
                                {{ $card['incident_date'] }}
                            </span>
                            @endif
                        </div>

                        @if(($card['title'] ?? null))
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mt-1">{{ $card['title'] }}</p>
                        @endif

                        @if(($card['reason'] ?? null))
                        <p class="text-xs text-gray-600 dark:text-gray-400 mt-1 leading-relaxed">{{ $card['reason'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="flex items-center gap-1.5 mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
            <x-filament::icon icon="heroicon-o-information-circle" class="w-3.5 h-3.5! text-gray-400!" />
            <span class="text-xs text-gray-400 dark:text-gray-500">AI-detected — verify independently. Deep = same root cause mechanism, Thematic = related theme.</span>
        </div>
    @endif
</x-filament::section>
