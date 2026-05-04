<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Incidents by Severity
        </x-slot>

        <div class="overflow-x-auto" style="max-height: 300px; overflow-y: auto;">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Severity
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Count
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Percentage
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($this->severityData as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <x-filament::badge :color="$this->getSeverityColor($row['severity'])">
                                    {{ $row['severity'] }}
                                </x-filament::badge>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                <span class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $row['count'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                <x-filament::badge :color="$this->getPercentageColor($row['percentage'])">
                                    {{ number_format($row['percentage'], 1) }}%
                                </x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-12">
                                <div class="flex flex-col items-center gap-3">
                                    <x-filament::icon
                                        icon="heroicon-o-document-text"
                                        class="h-12 w-12 text-gray-400"
                                    />
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                        No incidents found
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
