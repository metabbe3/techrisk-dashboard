<x-filament-panels::page>
    @php
        $weeklyData = $this->getWeeklyData();
        $totalOpen = collect($weeklyData)->sum('incident_open');
        $totalClosed = collect($weeklyData)->sum('incident_closed');
        $grandTotal = collect($weeklyData)->sum('total');
    @endphp

    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between w-full">
                <span>Weekly Incident Report - {{ $this->selectedYear }}</span>
                <x-filament::button
                    color="success"
                    tag="a"
                    href="{{ route('filament.admin.pages.weekly-report-export', ['year' => $this->selectedYear]) }}"
                    icon="heroicon-o-arrow-down-tray"
                    size="sm"
                >
                    Export XLS
                </x-filament::button>
            </div>
        </x-slot>

        <div class="max-w-xs">
            {{ $this->form }}
        </div>
    </x-filament::section>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Open Incidents -->
        <x-filament::section class="border-l-4 border-l-amber-500">
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 items-center justify-center rounded-lg bg-amber-50 dark:bg-amber-950">
                    <x-filament::icon
                        icon="heroicon-o-exclamation-triangle"
                        class="h-7 w-7 text-amber-600 dark:text-amber-400"
                    />
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Open Incidents</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $totalOpen }}</p>
                </div>
            </div>
        </x-filament::section>

        <!-- Closed Incidents -->
        <x-filament::section class="border-l-4 border-l-emerald-500">
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 items-center justify-center rounded-lg bg-emerald-50 dark:bg-emerald-950">
                    <x-filament::icon
                        icon="heroicon-o-check-circle"
                        class="h-7 w-7 text-emerald-600 dark:text-emerald-400"
                    />
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Closed Incidents</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $totalClosed }}</p>
                </div>
            </div>
        </x-filament::section>

        <!-- Total Incidents -->
        <x-filament::section class="border-l-4 border-l-primary-600">
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 items-center justify-center rounded-lg bg-primary-50 dark:bg-primary-950">
                    <x-filament::icon
                        icon="heroicon-o-chart-bar"
                        class="h-7 w-7 text-primary-600 dark:text-primary-400"
                    />
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Incidents</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $grandTotal }}</p>
                </div>
            </div>
        </x-filament::section>
    </div>

    @include('filament.forms.components.ai-weekly-summary')

    <!-- Weekly Data Table -->
    <x-filament::section>
        <x-slot name="heading">
            Weekly Breakdown
        </x-slot>

        <div class="overflow-x-auto" x-data="{ expandedWeek: null }">
            <table class="w-full">
                <caption class="sr-only">Weekly incident breakdown for {{ $this->selectedYear }}</caption>
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 w-8"></th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Week
                        </th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Period
                        </th>
                        <th scope="col" class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Open
                        </th>
                        <th scope="col" class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Closed
                        </th>
                        <th scope="col" class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Total
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($weeklyData as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors cursor-pointer"
                            @click="expandedWeek === '{{ $row->week }}' ? expandedWeek = null : expandedWeek = '{{ $row->week }}'">
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($row->total > 0)
                                    <svg class="w-4 h-4 text-gray-400 transition-transform duration-200 dark:text-gray-500"
                                         :class="expandedWeek === '{{ $row->week }}' ? 'rotate-90' : ''"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path d="M9 5l7 7-7 7"/>
                                    </svg>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <x-filament::badge color="primary" size="sm">
                                    {{ $row->week }}
                                </x-filament::badge>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                {{ $row->date_range }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                @if($row->incident_open > 0)
                                    <x-filament::badge color="warning" size="sm">{{ $row->incident_open }}</x-filament::badge>
                                @else
                                    <span class="text-gray-400 dark:text-gray-600 text-sm">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                @if($row->incident_closed > 0)
                                    <x-filament::badge color="success" size="sm">{{ $row->incident_closed }}</x-filament::badge>
                                @else
                                    <span class="text-gray-400 dark:text-gray-600 text-sm">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                @if($row->total > 0)
                                    <x-filament::badge color="primary" size="sm">{{ $row->total }}</x-filament::badge>
                                @else
                                    <span class="text-gray-400 dark:text-gray-600 text-sm">&mdash;</span>
                                @endif
                            </td>
                        </tr>

                        <!-- Expanded Incident Details -->
                        @if($row->incidents->count() > 0)
                        <tr x-show="expandedWeek === '{{ $row->week }}'" x-transition>
                            <td colspan="6" class="px-0 py-0">
                                <div class="bg-gray-50 dark:bg-gray-900/50 border-t border-b border-gray-200 dark:border-gray-700">
                                    <div class="px-6 py-3">
                                        <div class="grid gap-3">
                                            @foreach($row->incidents as $incident)
                                                @php
                                                    $sevEnum = \App\Enums\Severity::tryFrom($incident->severity);
                                                    $sevColor = $sevEnum ? $sevEnum->color() : 'gray';
                                                    $statusEnum = \App\Enums\IncidentStatus::tryFrom($incident->incident_status);
                                                    $statusColor = $statusEnum ? $statusEnum->color() : 'gray';
                                                    $incidentUrl = route('filament.admin.resources.incidents.view', ['record' => $incident->no]);
                                                @endphp
                                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-shadow">
                                                    <div class="px-4 py-3">
                                                        <div class="flex items-start justify-between gap-3">
                                                            <div class="flex-1 min-w-0">
                                                                <div class="flex items-center gap-2 mb-1 flex-wrap">
                                                                    <a href="{{ $incidentUrl }}" target="_blank" class="text-sm font-semibold text-primary-600 dark:text-primary-400 hover:underline">
                                                                        {{ $incident->no }}
                                                                    </a>
                                                                    <x-filament::badge :color="$sevColor" size="sm">{{ $incident->severity }}</x-filament::badge>
                                                                    <x-filament::badge :color="$statusColor" size="sm">{{ $incident->incident_status }}</x-filament::badge>
                                                                    @if($incident->fund_loss > 0)
                                                                        <x-filament::badge color="danger" size="sm">Loss: {{ number_format($incident->fund_loss, 0) }}</x-filament::badge>
                                                                    @endif
                                                                    @if($incident->potential_fund_loss > 0 && !$incident->fund_loss)
                                                                        <x-filament::badge color="warning" size="sm">Potential: {{ number_format($incident->potential_fund_loss, 0) }}</x-filament::badge>
                                                                    @endif
                                                                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ $incident->incident_date?->format('M j, Y') }}</span>
                                                                </div>
                                                                @if($incident->title)
                                                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mb-0.5">{{ $incident->title }}</p>
                                                                @endif
                                                                @if($incident->summary)
                                                                    <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2">{{ Str::limit($incident->summary, 200) }}</p>
                                                                @endif
                                                            </div>
                                                            <a href="{{ $incidentUrl }}" target="_blank"
                                                               class="flex-shrink-0 inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-md bg-primary-50 text-primary-700 hover:bg-primary-100 dark:bg-primary-900/30 dark:text-primary-400 dark:hover:bg-primary-900/50 transition-colors">
                                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                    <path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                                                    <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7Z"/>
                                                                </svg>
                                                                View
                                                            </a>
                                                        </div>

                                                        <!-- Evidence / Proof -->
                                                        @if($incident->evidence || $incident->evidence_link)
                                                            <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700">
                                                                <div class="flex items-center gap-2 flex-wrap">
                                                                    <span class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider">Evidence:</span>
                                                                    @if($incident->evidence)
                                                                        <span class="text-xs text-gray-600 dark:text-gray-300">{{ Str::limit($incident->evidence, 150) }}</span>
                                                                    @endif
                                                                    @if($incident->evidence_link)
                                                                        @php
                                                                            $links = is_array($incident->evidence_link) ? $incident->evidence_link : array_filter(explode(',', $incident->evidence_link));
                                                                        @endphp
                                                                        @foreach($links as $link)
                                                                            @php $link = trim($link); @endphp
                                                                            @if($link)
                                                                                <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                                                                                   class="inline-flex items-center gap-1 text-xs text-primary-600 dark:text-primary-400 hover:underline">
                                                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                                        <path d="M13.828 10.172a4 4 0 0 0-5.656 0l-4 4a4 4 0 1 0 5.656 5.656l1.102-1.101"/>
                                                                                        <path d="M10.172 13.828a4 4 0 0 0 5.656 0l4-4a4 4 0 0 0-5.656-5.656l-1.102 1.101"/>
                                                                                    </svg>
                                                                                    {{ Str::limit(basename($link), 40) }}
                                                                                </a>
                                                                            @endif
                                                                        @endforeach
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <x-filament::icon
                                        icon="heroicon-o-document-text"
                                        class="h-12 w-12 text-gray-400"
                                    />
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No incidents found</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
