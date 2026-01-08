<x-filament-widgets::widget>
    <x-filament::card>
        <x-slot name="heading">
            {{ $this->heading }}
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        @foreach($this->columns as $column)
                            <th scope="col" class="px-6 py-3">
                                {{ $column }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->getTableData() as $row)
                        <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                            @foreach($this->columns as $column)
                                <td class="px-6 py-4">
                                    {{ $row[$column] ?? '' }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::card>
</x-filament-widgets::widget>
