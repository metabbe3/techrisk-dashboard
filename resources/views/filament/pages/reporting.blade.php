<x-filament-panels::page>
    <form wire:submit.prevent="generateReport">
        {{ $this->form }}

        <div class="mt-8 flex">
            <x-filament::button type="submit" class="mr-4">
                Generate Report
            </x-filament::button>



            @if(!empty($incidents))
                <x-filament::button wire:click="export">
                    Export to Excel
                </x-filament::button>
            @endif
        </div>
    </form>

    @if(!empty($metrics))
        <div class="mt-6">
            <h2 class="text-lg font-semibold">Metrics</h2>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($metrics as $key => $value)
                    <div class="bg-white overflow-hidden shadow rounded-lg">
                        <div class="p-5">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <svg class="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                </div>
                                <div class="ml-5 w-0 flex-1">
                                    <dl>
                                        <dt class="text-sm font-medium text-gray-500 truncate">
                                            {{ str_replace('_', ' ', Str::title($key)) }}
                                        </dt>
                                        <dd class="text-lg font-semibold text-gray-900">
                                            {{ is_numeric($value) ? number_format($value, 2) : $value }}
                                        </dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($this->form->getState()['columns']))
        <div class="mt-6">
            <h2 class="text-lg font-semibold">Incidents</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                        <tr>
                            @foreach($this->form->getState()['columns'] as $column)
                                <th scope="col" class="px-6 py-3">{{ $this->getColumnsFlattened()[$column] ?? $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($incidents as $incident)
                            <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                                @foreach($this->form->getState()['columns'] as $column)
                                    <td class="px-6 py-4">
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
        </div>
    @endif
</x-filament-panels::page>