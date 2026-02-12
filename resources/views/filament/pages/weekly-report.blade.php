<x-filament-panels::page>
    <div class="fi-section-header fi-section-header-has-description">
        <div class="fi-section-header-heading">
            <h2 class="fi-section-header-title">
                Weekly Incident Report - {{ $this->selectedYear }}
            </h2>
            <span class="fi-section-header-description">
                Weekly incident tracking and statistics
            </span>
        </div>
    </div>

    <x-slot name="content">
        {{ \Filament\Support\Facades\FilamentView::renderHook('panels::page.before', []) }}

        <!-- Year Filter Form -->
        <div class="mb-6">
            {{ $this->form }}
        </div>

        <!-- Summary Stats -->
        @php
            $data = collect($this->getWeeklyData());
            $totalOpen = $data->sum('incident_open');
            $totalClosed = $data->sum('incident_closed');
            $grandTotal = $data->sum('total');
        @endphp

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg p-4">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Open</div>
                <div class="mt-2 text-2xl font-semibold text-yellow-600 dark:text-yellow-400">{{ $totalOpen }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg p-4">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Closed</div>
                <div class="mt-2 text-2xl font-semibold text-green-600 dark:text-green-400">{{ $totalClosed }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg p-4">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Grand Total</div>
                <div class="mt-2 text-2xl font-semibold text-blue-600 dark:text-blue-400">{{ $grandTotal }}</div>
            </div>
        </div>

        <!-- Table -->
        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook('panels::page.after', []) }}
    </x-slot>
</x-filament-panels::page>
