<x-filament-widgets::widget>
    <x-filament::section style="max-height: 310px;">
        <x-slot name="heading">
            Incidents by Severity
        </x-slot>

        <div class="severity-scroll" style="max-height: 200px; overflow-y: auto;">
            @forelse($this->severityData as $row)
                @php
                    $barClass = match($row['severity']) {
                        'P1' => 'severity-p1',
                        'P2' => 'severity-p2',
                        'P3' => 'severity-p3',
                        'P4' => 'severity-p4',
                        default => 'severity-default',
                    };
                @endphp
                <div class="flex items-center gap-3 py-2 px-1 border-b border-gray-100 dark:border-gray-800 last:border-0">
                    <div class="w-16 flex-shrink-0">
                        <x-filament::badge :color="$this->getSeverityColor($row['severity'])">
                            {{ $row['severity'] }}
                        </x-filament::badge>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="severity-bar w-full">
                            <div class="severity-bar-fill {{ $barClass }}" style="width: {{ min($row['percentage'] * 3, 100) }}%"></div>
                        </div>
                    </div>
                    <div class="w-10 text-center flex-shrink-0">
                        <x-filament::badge :color="$this->getSeverityColor($row['severity'])">
                            {{ $row['count'] }}
                        </x-filament::badge>
                    </div>
                    <div class="w-14 text-right flex-shrink-0">
                        <x-filament::badge :color="$this->getPercentageColor($row['percentage'])" size="sm">
                            {{ number_format($row['percentage'], 1) }}%
                        </x-filament::badge>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center gap-3 py-8">
                    <x-filament::icon
                        icon="heroicon-o-chart-bar"
                        class="h-10 w-10 text-gray-400"
                    />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        No incidents found
                    </p>
                </div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
