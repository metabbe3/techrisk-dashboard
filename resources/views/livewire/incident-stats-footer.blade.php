@php
    $statCards = [
        ['label' => 'Total Cases', 'value' => number_format($stats['totalCases']), 'icon' => 'heroicon-o-chart-bar', 'iconBg' => 'bg-indigo-100 dark:bg-indigo-900/40', 'iconColor' => 'text-indigo-600 dark:text-indigo-400', 'border' => 'border-l-indigo-500'],
        ['label' => 'Avg MTTR (Mins)', 'value' => number_format($stats['avgMttrMins'], 2), 'icon' => 'heroicon-o-clock', 'iconBg' => 'bg-sky-100 dark:bg-sky-900/40', 'iconColor' => 'text-sky-600 dark:text-sky-400', 'border' => 'border-l-sky-500'],
        ['label' => 'Avg MTTR (Days)', 'value' => number_format($stats['avgMttrDays'], 2), 'icon' => 'heroicon-o-clock', 'iconBg' => 'bg-orange-100 dark:bg-orange-900/40', 'iconColor' => 'text-orange-600 dark:text-orange-400', 'border' => 'border-l-orange-500'],
        ['label' => 'Avg MTBF (Days)', 'value' => number_format($stats['avgMtbf'], 2), 'icon' => 'heroicon-o-shield-check', 'iconBg' => 'bg-violet-100 dark:bg-violet-900/40', 'iconColor' => 'text-violet-600 dark:text-violet-400', 'border' => 'border-l-violet-500'],
        ['label' => 'Potential Loss', 'value' => 'Rp '.number_format($stats['totalPotentialFundLoss'], 0, ',', '.'), 'icon' => 'heroicon-o-exclamation-triangle', 'iconBg' => 'bg-amber-100 dark:bg-amber-900/40', 'iconColor' => 'text-amber-600 dark:text-amber-400', 'border' => 'border-l-amber-500'],
        ['label' => 'Total Loss', 'value' => 'Rp '.number_format($stats['totalFundLoss'], 0, ',', '.'), 'icon' => 'heroicon-o-arrow-trending-down', 'iconBg' => 'bg-rose-100 dark:bg-rose-900/40', 'iconColor' => 'text-rose-600 dark:text-rose-400', 'border' => 'border-l-rose-500'],
        ['label' => 'Recovered Fund', 'value' => 'Rp '.number_format($stats['totalRecoveredFund'], 0, ',', '.'), 'icon' => 'heroicon-o-arrow-trending-up', 'iconBg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'iconColor' => 'text-emerald-600 dark:text-emerald-400', 'border' => 'border-l-emerald-500'],
    ];
@endphp

<x-filament::section>
    <x-slot name="heading">
        Summary
    </x-slot>

    <div class="grid grid-cols-2 md:grid-cols-4 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))] gap-3">
        @foreach($statCards as $card)
            <div class="relative rounded-xl bg-white dark:bg-gray-800/60 p-4 ring-1 ring-gray-950/5 dark:ring-white/5 border-l-[3px] {{ $card['border'] }} transition-all duration-200 hover:shadow-lg hover:-translate-y-0.5 overflow-hidden group">
                <div class="absolute inset-0 bg-gradient-to-br from-transparent to-gray-50/50 dark:from-transparent dark:to-gray-900/20 pointer-events-none"></div>
                <div class="relative min-w-0">
                    <div class="flex items-center gap-2 mb-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg {{ $card['iconBg'] }} transition-transform duration-200 group-hover:scale-110">
                            <x-filament::icon icon="{{ $card['icon'] }}" class="h-4 w-4 {{ $card['iconColor'] }}" />
                        </div>
                    </div>
                    <p class="text-[11px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider truncate">{{ $card['label'] }}</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5 tabular-nums truncate">{{ $card['value'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</x-filament::section>
