<x-filament-panels::page>
    <form wire:submit.prevent="generateReport">
        {{ $this->form }}

        <div class="mt-8 flex gap-3">
            <x-filament::button type="submit">
                Generate Report
            </x-filament::button>

            @if(!empty($incidents))
                <x-filament::button color="success" wire:click="export">
                    Export to Excel
                </x-filament::button>
            @endif
        </div>
    </form>

    @if(!empty($metrics))
        <x-filament::section>
            <x-slot name="heading">
                Metrics
            </x-slot>

            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-px bg-gray-200 dark:bg-gray-700 rounded-lg overflow-hidden">
                @foreach($metrics as $key => $value)
                    <div class="px-4 py-3 bg-white dark:bg-gray-900">
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ str_replace('_', ' ', Str::title($key)) }}
                        </dt>
                        <dd class="mt-0.5 text-lg font-bold text-gray-900 dark:text-white">
                            {{ is_numeric($value) ? number_format($value, 2) : $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    @endif

    @php
        $selectedColumns = $this->selectedColumns ?? [];
        $columnLabels = $this->getColumnsFlattened();
    @endphp

    @if(!empty($selectedColumns))
        <x-filament::section>
            <x-slot name="heading">
                Incidents
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            @foreach($selectedColumns as $column)
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    {{ $columnLabels[$column] ?? $column }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($incidents as $incident)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                                @foreach($selectedColumns as $column)
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                        @php
                                            $value = Arr::get($incident, $column);
                                            if (is_array($value)) {
                                                echo implode(', ', $value);
                                            } else {
                                                echo $value;
                                            }
                                        @endphp
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
