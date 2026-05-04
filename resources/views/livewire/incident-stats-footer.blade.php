@php
    $statCards = [
        ['label' => 'Total Cases', 'value' => number_format($stats['totalCases']), 'color' => 'text-gray-900 dark:text-white'],
        ['label' => 'Avg MTTR (Mins)', 'value' => number_format($stats['avgMttr'], 2), 'color' => 'text-gray-900 dark:text-white'],
        ['label' => 'Avg MTBF (Days)', 'value' => number_format($stats['avgMtbf'], 2), 'color' => 'text-gray-900 dark:text-white'],
        ['label' => 'Potential Loss', 'value' => 'Rp '.number_format($stats['totalPotentialFundLoss'], 0, ',', '.'), 'color' => 'text-gray-900 dark:text-white'],
        ['label' => 'Total Loss', 'value' => 'Rp '.number_format($stats['totalFundLoss'], 0, ',', '.'), 'color' => 'text-red-600 dark:text-red-500'],
        ['label' => 'Recovered Fund', 'value' => 'Rp '.number_format($stats['totalRecoveredFund'], 0, ',', '.'), 'color' => 'text-green-600 dark:text-green-500'],
    ];
@endphp

<x-filament::section>
    <x-slot name="heading">
        Summary
    </x-slot>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        @foreach($statCards as $card)
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                <p class="text-2xl font-bold {{ $card['color'] }}">{{ $card['value'] }}</p>
            </div>
        @endforeach
    </div>
</x-filament::section>
