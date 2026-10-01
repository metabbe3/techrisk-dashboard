<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Incident Memory (long-term)
        </x-slot>

        <x-slot name="description">
            Feeds AI agents with "Include incident catalog" on — one line per incident, newest first. Files at storage/app/markdown/corpus.
        </x-slot>

        @php($status = $this->memoryStatus())

        @if (! $status['built'])
            <p class="text-sm font-medium text-warning-500">
                Never built — press Rebuild Incident Memory.
            </p>
        @else
            <p class="text-sm text-gray-700 dark:text-gray-300">
                <span class="font-medium">{{ $status['incidents'] }} incidents</span>
                · built {{ \Illuminate\Support\Carbon::parse($status['built_at'])->format('d M Y H:i') }}
                @if ($status['stale'])
                    · <span class="font-medium text-warning-500">Stale</span> (hourly refresh pending)
                @else
                    · <span class="font-medium text-success-600 dark:text-success-400">Fresh</span>
                @endif
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
