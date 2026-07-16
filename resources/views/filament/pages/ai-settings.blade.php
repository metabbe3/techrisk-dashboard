<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit" color="primary">
                Save Settings
            </x-filament::button>
        </div>
    </form>

    @php
        $modelHealth = app(\App\Services\Ai\AiTextService::class)->getModelsHealth();
        $statusColor = [
            'healthy' => 'success',
            'slow' => 'warning',
            'unhealthy' => 'danger',
            'unknown' => 'gray',
        ];
    @endphp

    <x-filament::section class="mt-6">
        <x-slot name="heading">Model Health</x-slot>
        <x-slot name="description">Reachability + latency from the last gateway ping. Click <strong>Check Model Health</strong> in AI Models above to refresh now (otherwise it auto-refreshes every 15 minutes).</x-slot>

        @if (empty($modelHealth))
            <p class="text-sm text-gray-500 dark:text-gray-400">No models configured.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th scope="col" class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">Model</th>
                            <th scope="col" class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">Status</th>
                            <th scope="col" class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">Latency</th>
                            <th scope="col" class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">Checked</th>
                            <th scope="col" class="py-2 font-medium text-gray-500 dark:text-gray-400">Error</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($modelHealth as $modelId => $health)
                            <tr>
                                <td class="py-2 pr-4 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $modelId }}</td>
                                <td class="py-2 pr-4">
                                    <x-filament::badge :color="$statusColor[$health['status']] ?? 'gray'">
                                        {{ ucfirst($health['status']) }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">
                                    {{ isset($health['latency_ms']) ? number_format($health['latency_ms']).' ms' : '—' }}
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                    {{ isset($health['checked_at']) ? \Carbon\Carbon::parse($health['checked_at'])->diffForHumans() : 'Never' }}
                                </td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ $health['error'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
