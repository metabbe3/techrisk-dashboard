@props(['incident', 'expanded' => false])

{{-- Incidents list ID cell (owner 2026-11-25): the PROJ-022 sub-incident
     chevron lives inside the ID cell — no separate expand column, so the
     table keeps the DOHA-page-like natural column layout. Sub-less rows get
     an invisible spacer to keep IDs aligned. --}}
<div class="tr-id-cell">
    @if ($incident->sub_incidents_count > 0)
        <button
            type="button"
            class="tr-expand-btn"
            wire:click="toggleExpandedSubIncidents({{ $incident->getKey() }})"
            aria-label="Toggle sub-incidents of {{ $incident->no }}"
            aria-expanded="{{ $expanded ? 'true' : 'false' }}"
        >
            <x-filament::icon :icon="$expanded ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'" />
        </button>
    @else
        <span class="tr-expand-spacer" aria-hidden="true"></span>
    @endif

    <span>{{ $incident->no }}</span>
</div>
