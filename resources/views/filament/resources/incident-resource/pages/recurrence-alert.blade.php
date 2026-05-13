@php
    $inc = $entry->getRecord();
    $data = $inc->recurrence_data;

    if (!$data || !($data['is_recurring'] ?? false)) {
        return;
    }

    $matches = $data['matches'] ?? [];
    $aiAnalysis = $data['ai_analysis'] ?? '';
    $matchCount = count($matches);
@endphp

<div x-data="{ expanded: true }">
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5! text-primary-500!" />
                    <span>Recurrence Detected</span>
                    <x-filament::badge color="warning">{{ $matchCount }} match{{ $matchCount !== 1 ? 'es' : '' }}</x-filament::badge>
                </div>
                <button @click="expanded = !expanded" type="button" class="cursor-pointer p-1 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <span class="inline-flex transition-transform duration-200" :class="expanded ? 'rotate-180' : ''">
                        <x-filament::icon icon="heroicon-o-chevron-down" class="w-5 h-5! text-gray-400!" />
                    </span>
                </button>
            </div>
        </x-slot>

        <div x-show="expanded" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="space-y-4">
                @if($aiAnalysis)
                <x-filament::section color="warning">
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-light-bulb" class="w-4 h-4! text-primary-500!" />
                            <span>AI Analysis</span>
                        </div>
                    </x-slot>
                    <div class="prose prose-sm dark:prose-invert max-w-none text-sm leading-relaxed">
                        {!! \Illuminate\Support\Str::markdown($aiAnalysis) !!}
                    </div>
                    <div class="flex items-center gap-1.5 mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                        <x-filament::icon icon="heroicon-o-information-circle" class="w-3.5 h-3.5! text-gray-400!" />
                        <span class="text-xs text-gray-400">AI-generated analysis may be inaccurate. Please verify independently.</span>
                    </div>
                </x-filament::section>
                @endif

                @if(!empty($matches))
                <div class="space-y-3">
                    @foreach($matches as $match)
                    @php
                        $score = $match['score'] ?? 0;
                        $isHighScore = $score >= 6;
                        $severity = $match['severity'] ?? null;
                        $sevColor = match($severity) { 'P1' => 'danger', 'P2' => 'warning', 'P3' => 'info', 'P4' => 'primary', default => 'gray' };
                    @endphp
                    <div class="flex items-start gap-3 p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-white/5">
                        @if($score > 0)
                        <div class="flex-shrink-0 mt-0.5" title="Similarity score: {{ $score }}/10 — {{ $isHighScore ? 'High similarity' : 'Moderate similarity' }}">
                            <x-filament::badge :color="$isHighScore ? 'danger' : 'warning'" size="sm">
                                <span class="font-bold">{{ $score }}/10</span>
                            </x-filament::badge>
                        </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <a href="{{ url('/admin/incidents/' . ($match['id'] ?? '')) }}" class="text-sm font-semibold text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 underline-offset-2 hover:underline">{{ $match['no'] ?? 'N/A' }}</a>
                                @if($severity)
                                <x-filament::badge :color="$sevColor" size="sm">{{ $severity }}</x-filament::badge>
                                @endif
                                @if(($match['incident_status'] ?? null))
                                <x-filament::badge
                                    :color="in_array($match['incident_status'], ['Completed', 'Resolved', 'Closed']) ? 'success' : 'gray'"
                                    size="sm"
                                >{{ $match['incident_status'] }}</x-filament::badge>
                                @endif
                                @if(($match['incident_date'] ?? null))
                                <span class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                    <x-filament::icon icon="heroicon-o-calendar" class="w-3 h-3! text-gray-400!" />
                                    {{ $match['incident_date'] }}
                                </span>
                                @endif
                            </div>

                            @if(($match['summary'] ?? null))
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1.5 line-clamp-2 leading-relaxed">{{ $match['summary'] }}</p>
                            @endif

                            @if(($match['overdue_actions'] ?? 0) > 0 || ($match['pending_actions'] ?? 0) > 0 || ($match['completed_actions'] ?? 0) > 0)
                            <div class="flex flex-wrap gap-1.5 mt-2.5">
                                @if(($match['overdue_actions'] ?? 0) > 0)
                                <x-filament::badge color="danger" size="sm">
                                    <span class="flex items-center gap-1">
                                        <x-filament::icon icon="heroicon-o-exclamation-circle" class="w-3 h-3! text-danger-500!" />
                                        {{ $match['overdue_actions'] }} overdue
                                    </span>
                                </x-filament::badge>
                                @endif
                                @if(($match['pending_actions'] ?? 0) > 0)
                                <x-filament::badge color="warning" size="sm">
                                    <span class="flex items-center gap-1">
                                        <x-filament::icon icon="heroicon-o-clock" class="w-3 h-3! text-warning-500!" />
                                        {{ $match['pending_actions'] }} pending
                                    </span>
                                </x-filament::badge>
                                @endif
                                @if(($match['completed_actions'] ?? 0) > 0)
                                <x-filament::badge color="success" size="sm">
                                    <span class="flex items-center gap-1">
                                        <x-filament::icon icon="heroicon-o-check-circle" class="w-3 h-3! text-success-500!" />
                                        {{ $match['completed_actions'] }} completed
                                    </span>
                                </x-filament::badge>
                                @endif
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</div>
