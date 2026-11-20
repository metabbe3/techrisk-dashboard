<div class="space-y-6">
    @php($chainLabels = ['Direct lead', '2 levels up', '3 levels up'])

    <div class="space-y-3">
        @forelse ($chain as $i => $manager)
            <div class="flex items-center gap-3">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-20 shrink-0">{{ $chainLabels[$i] }}</span>
                <div class="flex-1 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $manager->name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $manager->email }}</p>
                    @if ($manager->job_title || $manager->department)
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                            @if ($manager->job_title)<span class="font-medium">{{ $manager->job_title }}</span>@endif
                            @if ($manager->department) — {{ $manager->department }}@endif
                        </p>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">No manager linked — run the Entra directory sync or set one manually.</p>
        @endforelse
    </div>

    <div>
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Team members</p>
        @if ($teammates->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No teammates sharing the same lead.</p>
        @else
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($teammates as $mate)
                    <div class="rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $mate->name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $mate->email }}</p>
                        @if ($mate->job_title)
                            <p class="text-xs text-gray-600 dark:text-gray-300">{{ $mate->job_title }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
