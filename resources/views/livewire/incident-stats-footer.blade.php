@php
    $statCards = [
        ['label' => 'Total Cases', 'value' => number_format($stats['totalCases']), 'icon' => 'heroicon-o-chart-bar', 'iconBg' => 'bg-indigo-50 dark:bg-indigo-950', 'iconColor' => 'text-indigo-600 dark:text-indigo-400', 'border' => 'border-l-4 border-l-indigo-500'],
        ['label' => 'Avg MTTR (Mins)', 'value' => number_format($stats['avgMttrMins'], 2), 'icon' => 'heroicon-o-clock', 'iconBg' => 'bg-sky-50 dark:bg-sky-950', 'iconColor' => 'text-sky-600 dark:text-sky-400', 'border' => 'border-l-4 border-l-sky-500'],
        ['label' => 'Avg MTTR (Days)', 'value' => number_format($stats['avgMttrDays'], 2), 'icon' => 'heroicon-o-clock', 'iconBg' => 'bg-orange-50 dark:bg-orange-950', 'iconColor' => 'text-orange-600 dark:text-orange-400', 'border' => 'border-l-4 border-l-orange-500'],
        ['label' => 'Avg MTBF (Days)', 'value' => number_format($stats['avgMtbf'], 2), 'icon' => 'heroicon-o-shield-check', 'iconBg' => 'bg-violet-50 dark:bg-violet-950', 'iconColor' => 'text-violet-600 dark:text-violet-400', 'border' => 'border-l-4 border-l-violet-500'],
        ['label' => 'Potential Loss', 'value' => 'Rp '.number_format($stats['totalPotentialFundLoss'], 0, ',', '.'), 'icon' => 'heroicon-o-exclamation-triangle', 'iconBg' => 'bg-amber-50 dark:bg-amber-950', 'iconColor' => 'text-amber-600 dark:text-amber-400', 'border' => 'border-l-4 border-l-amber-500'],
        ['label' => 'Total Loss', 'value' => 'Rp '.number_format($stats['totalFundLoss'], 0, ',', '.'), 'icon' => 'heroicon-o-arrow-trending-down', 'iconBg' => 'bg-rose-50 dark:bg-rose-950', 'iconColor' => 'text-rose-600 dark:text-rose-400', 'border' => 'border-l-4 border-l-rose-500'],
        ['label' => 'Recovered Fund', 'value' => 'Rp '.number_format($stats['totalRecoveredFund'], 0, ',', '.'), 'icon' => 'heroicon-o-arrow-trending-up', 'iconBg' => 'bg-emerald-50 dark:bg-emerald-950', 'iconColor' => 'text-emerald-600 dark:text-emerald-400', 'border' => 'border-l-4 border-l-emerald-500'],
    ];
@endphp

<x-filament::section>
    <x-slot name="heading">
        Summary
    </x-slot>

    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
        @foreach($statCards as $card)
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10 {{ $card['border'] }} transition-all hover:shadow-md hover:-translate-y-0.5">
                <div class="flex items-center gap-2 mb-2">
                    <div class="flex h-8 w-8 items-center justify-center rounded-md {{ $card['iconBg'] }}">
                        <x-filament::icon icon="{{ $card['icon'] }}" class="h-4 w-4 {{ $card['iconColor'] }}" />
                    </div>
                </div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $card['value'] }}</p>
            </div>
        @endforeach
    </div>
</x-filament::section>
