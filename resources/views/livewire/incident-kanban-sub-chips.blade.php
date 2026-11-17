{{-- PROJ-022: hierarchy chips on kanban cards — included by incident-kanban-board --}}
@if ($incident->parent_incident_id && $incident->superIncident)
    <span
        class="inline-flex items-center gap-0.5 text-[10px] font-medium text-dana-600 dark:text-dana-400 cursor-pointer hover:underline"
        title="Sub of {{ $incident->superIncident->no }} — {{ $incident->superIncident->title }}"
        wire:click.stop="viewIncident({{ $incident->parent_incident_id }})"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
        Sub of {{ $incident->superIncident->no }}
    </span>
@endif
@if (($incident->sub_incidents_count ?? 0) > 0)
    <span class="inline-flex items-center text-[10px] font-medium text-dana-600 dark:text-dana-400">
        {{ $incident->sub_incidents_count }} sub{{ $incident->sub_incidents_count === 1 ? '' : 's' }}
    </span>
@endif
