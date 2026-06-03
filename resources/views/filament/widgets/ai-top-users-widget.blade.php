<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Top Users by Token Usage (30d)
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-700">#</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-700">User</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-700">Tokens</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-700">Requests</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-700">Avg Response</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($this->getTopUsers() as $i => $user)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                            <td class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{{ $loop->iteration }}</td>
                            <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $user->user_email }}</td>
                            <td class="px-4 py-2 text-sm text-right font-mono text-gray-700 dark:text-gray-300">{{ number_format($user->total_tokens) }}</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-700 dark:text-gray-300">{{ number_format($user->request_count) }}</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-700 dark:text-gray-300">{{ round($user->avg_response_time) }}ms</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
