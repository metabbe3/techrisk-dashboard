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

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($metrics as $key => $value)
                    <x-filament::section>
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 dark:bg-primary-900/30">
                                <x-filament::icon
                                    icon="heroicon-o-chart-bar"
                                    class="h-5 w-5 text-primary-600 dark:text-primary-400"
                                />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ str_replace('_', ' ', Str::title($key)) }}
                                </p>
                                <p class="text-xl font-bold text-gray-900 dark:text-white">
                                    {{ is_numeric($value) ? number_format($value, 2) : $value }}
                                </p>
                            </div>
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @php
        $formState = $this->form->getState();
        $selectedColumns = $formState['columns'] ?? [];
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
