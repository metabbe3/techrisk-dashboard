@php
    use App\Enums\FundStatus;
    use App\Enums\IncidentStatus;
    use App\Enums\Severity;

    $record = $entry->getRecord();
    $subs = $record->subIncidents;
@endphp

<div class="divide-y divide-gray-100 dark:divide-gray-800">
    @foreach ($subs as $sub)
        <div class="flex flex-wrap items-center justify-between gap-2 py-1.5">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <a href="{{ \App\Filament\Resources\IncidentResource::getUrl('view', ['record' => $sub->getKey()]) }}"
                    class="font-semibold text-primary-600 hover:underline dark:text-primary-400">
                    {{ $sub->no }}
                </a>
                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $sub->title }}</span>
                <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    {{ $sub->incident_status }}
                </span>
                <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    {{ $sub->severity }}
                </span>
                <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    {{ $sub->fund_status }}
                </span>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $sub->incident_date?->format('d M Y, H:i') }}
                </span>
            </div>
            @if (auth()->user()?->can('manage incidents'))
                <button type="button"
                    wire:click="unlinkSubIncident({{ $sub->getKey() }})"
                    wire:confirm="Unlink this sub-incident from {{ $record->no }}? Neither record is deleted."
                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold text-danger-600 hover:bg-danger-50 dark:text-danger-400 dark:hover:bg-danger-950"
                >
                    Unlink
                </button>
            @endif
        </div>
    @endforeach
</div>
