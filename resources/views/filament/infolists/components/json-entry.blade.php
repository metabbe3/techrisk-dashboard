@props([
    'entry',
])

<div {{ $attributes->class(['fi-in-text-entry']) }}>
    <label class="fi-in-text-entry-label block text-sm font-medium text-gray-950 dark:text-white">
        {{ $entry->getLabel() }}
    </label>

    @php
        $state = $entry->getState();
    @endphp

    @if(is_array($state) && !empty($state))
        <div class="mt-1 max-h-80 overflow-auto rounded-lg bg-gray-100 p-3 ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <pre class="whitespace-pre-wrap break-words text-xs leading-relaxed text-gray-800 dark:text-gray-100">{{ json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @else
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">No data</p>
    @endif
</div>
