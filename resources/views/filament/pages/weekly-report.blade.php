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

    <!-- Weekly Data Table -->
    <x-filament::section>
        <x-slot name="heading">
            Weekly Breakdown
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full">
                <caption class="sr-only">Weekly incident breakdown for {{ $this->selectedYear }}</caption>
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
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
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
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
                                    <span class="text-gray-400 dark:text-gray-600 text-sm">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                @if($row->incident_closed > 0)
                                    <x-filament::badge color="success" size="sm">{{ $row->incident_closed }}</x-filament::badge>
                                @else
                                    <span class="text-gray-400 dark:text-gray-600 text-sm">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                @if($row->total > 0)
                                    <x-filament::badge color="primary" size="sm">{{ $row->total }}</x-filament::badge>
                                @else
                                    <span class="text-gray-400 dark:text-gray-600 text-sm">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
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
