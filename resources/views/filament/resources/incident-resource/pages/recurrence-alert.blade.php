@php
    $inc = $entry->getRecord();
    $data = $inc->recurrence_data;

    if (!$data || !($data['is_recurring'] ?? false)) {
        return;
    }

    $matches = $data['matches'] ?? [];
    $aiAnalysis = $data['ai_analysis'] ?? '';
@endphp

<div class="mb-6 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 overflow-hidden">
    {{-- Left accent stripe --}}
    <div class="flex">
        <div class="w-1 flex-shrink-0" style="background: #f59e0b;"></div>
        <div class="flex-1 px-5 py-4">
            {{-- Header --}}
            <div class="flex items-center gap-2 mb-3">
                <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <span class="text-sm font-bold text-gray-900 dark:text-gray-100">Recurrence Detected</span>
                <span class="text-xs text-gray-400 dark:text-gray-500">&mdash; {{ count($matches) }} similar incident{{ count($matches) !== 1 ? 's' : '' }} found</span>
            </div>

            {{-- AI Analysis --}}
            @if($aiAnalysis)
            <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed mb-2">{{ $aiAnalysis }}</p>
            @endif

            {{-- Disclaimer --}}
            <p class="text-xs text-gray-400 dark:text-gray-500 mb-4">AI-generated analysis may be inaccurate. Please verify the results independently.</p>

            {{-- Matched Incidents --}}
            @if(!empty($matches))
            <div class="space-y-0 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($matches as $match)
                <div class="flex items-center gap-3 py-2.5 first:pt-0 {{ !$loop->last ? '' : '' }}">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <a href="{{ url('/admin/incidents/' . $match['id']) }}" class="text-sm font-semibold text-gray-900 dark:text-gray-100 hover:underline underline-offset-2">
                                {{ $match['no'] ?? 'N/A' }}
                            </a>
                            @if(($match['severity'] ?? null))
                                @php $sevColor = match($match['severity']) { 'P1' => '#dc2626', 'P2' => '#ea580c', 'P3' => '#d97706', 'P4' => '#2563eb', default => '#6b7280' }; @endphp
                                <span class="text-xs font-bold" style="color: {{ $sevColor }};">{{ $match['severity'] }}</span>
                            @endif
                            <span class="text-xs text-gray-300 dark:text-gray-600">&bull;</span>
                            @if(($match['incident_status'] ?? null))
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $match['incident_status'] }}</span>
                            @endif
                            <span class="text-xs text-gray-300 dark:text-gray-600">&bull;</span>
                            @if(($match['incident_date'] ?? null))
                            <span class="text-xs text-gray-400 dark:text-gray-500">{{ $match['incident_date'] }}</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">{{ $match['summary'] ?? '' }}</p>
                    </div>
                    {{-- Action status — compact --}}
                    @if(($match['overdue_actions'] ?? 0) > 0 || ($match['pending_actions'] ?? 0) > 0)
                    <div class="flex items-center gap-2 flex-shrink-0">
                        @if(($match['overdue_actions'] ?? 0) > 0)
                        <span class="text-xs font-semibold" style="color: #dc2626;">
                            {{ $match['overdue_actions'] }} overdue
                        </span>
                        @endif
                        @if(($match['pending_actions'] ?? 0) > 0)
                        <span class="text-xs font-semibold" style="color: #d97706;">
                            {{ $match['pending_actions'] }} pending
                        </span>
                        @endif
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
