@php
    use App\Enums\FundStatus;
    use App\Enums\IncidentStatus;
    use App\Enums\Severity;

    $columns = $this->columns;
    $incidents = $this->incidents;
    $totalCounts = $this->totalCounts;
    $severityOptions = $this->severityOptions;
    $incidentTypeOptions = $this->incidentTypeOptions;
    $fundStatusOptions = $this->fundStatusOptions;
    $picOptions = $this->picOptions;

    $statusColors = [
        'warning' => '#f59e0b',
        'info' => '#3b82f6',
        'primary' => '#6366f1',
        'success' => '#10b981',
    ];

    $activeFilterCount = count(array_filter([
        ! empty($severity) ? 'sev' : null,
        ! empty($incidentType) ? 'type' : null,
        $fundStatus ? 'fund' : null,
        ! empty($picId) ? 'pic' : null,
        strlen($searchQuery) >= 2 ? 'search' : null,
        $assignedToMe ? 'me' : null,
        $p1Only ? 'p1' : null,
        $unassignedOnly ? 'unassigned' : null,
        $fundLossOnly ? 'fundloss' : null,
    ]));
@endphp

<div
    x-data="kanbanBoard()"
    x-init="init()"
>
    {{-- Filter Bar --}}
    <div class="bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl p-3 mb-4 shadow-sm">
        <div class="flex items-center gap-3 flex-wrap">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors cursor-pointer
                    @if($activeFilterCount > 0) bg-indigo-50 dark:bg-indigo-500/10 border-indigo-200 dark:border-indigo-500/30 text-indigo-600 dark:text-indigo-400
                    @else bg-gray-50 dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-indigo-300 hover:text-indigo-600 dark:hover:border-indigo-500 dark:hover:text-indigo-400
                    @endif"
                x-on:click="filtersVisible = !filtersVisible"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                </svg>
                Filters
                @if($activeFilterCount > 0)
                    <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 text-[10px] font-bold rounded-full bg-indigo-500 text-white">{{ $activeFilterCount }}</span>
                @endif
            </button>

            <div class="flex-1 min-w-[200px] max-w-xs">
                <input
                    type="text"
                    aria-label="Search incidents by ID or title"
                    wire:model.live.debounce.300ms="searchQuery"
                    placeholder="Search ID or title..."
                    class="w-full px-3 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 placeholder-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 outline-none transition-colors"
                />
            </div>

            <select wire:model.live="quickPeriod" aria-label="Time period" class="px-2.5 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 outline-none cursor-pointer focus:border-indigo-400">
                <option value="year">This Year</option>
                <option value="quarter">This Quarter</option>
                <option value="month">This Month</option>
                <option value="all">All Time</option>
            </select>

            <button
                type="button"
                wire:click="resetFilters"
                class="px-2.5 py-1.5 text-xs font-medium rounded-lg border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 text-red-500 hover:bg-red-100 dark:hover:bg-red-500/15 transition-colors cursor-pointer"
            >
                Reset
            </button>
        </div>

        {{-- Quick Filter Pills --}}
        <div class="flex flex-wrap gap-2 items-center mt-3">
            <button
                type="button"
                wire:click="toggleQuickFilter('assignedToMe')"
                @class([
                    'px-3 py-1.5 rounded-full text-xs font-medium border transition-all duration-200 cursor-pointer flex items-center gap-2 select-none',
                    'bg-indigo-50 dark:bg-indigo-500/10 border-indigo-500 text-indigo-700 dark:text-indigo-400 ring-1 ring-indigo-500' => $assignedToMe,
                    'bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' => !$assignedToMe,
                ])
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                Assigned to Me
            </button>
            <button
                type="button"
                wire:click="toggleQuickFilter('p1Only')"
                @class([
                    'px-3 py-1.5 rounded-full text-xs font-medium border transition-all duration-200 cursor-pointer flex items-center gap-2 select-none',
                    'bg-indigo-50 dark:bg-indigo-500/10 border-indigo-500 text-indigo-700 dark:text-indigo-400 ring-1 ring-indigo-500' => $p1Only,
                    'bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' => !$p1Only,
                ])
            >
                <span class="w-2 h-2 rounded-full bg-red-500"></span>
                P1 Only
            </button>
            <button
                type="button"
                wire:click="toggleQuickFilter('unassignedOnly')"
                @class([
                    'px-3 py-1.5 rounded-full text-xs font-medium border transition-all duration-200 cursor-pointer flex items-center gap-2 select-none',
                    'bg-indigo-50 dark:bg-indigo-500/10 border-indigo-500 text-indigo-700 dark:text-indigo-400 ring-1 ring-indigo-500' => $unassignedOnly,
                    'bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' => !$unassignedOnly,
                ])
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                Unassigned
            </button>
            <button
                type="button"
                wire:click="toggleQuickFilter('fundLossOnly')"
                @class([
                    'px-3 py-1.5 rounded-full text-xs font-medium border transition-all duration-200 cursor-pointer flex items-center gap-2 select-none',
                    'bg-indigo-50 dark:bg-indigo-500/10 border-indigo-500 text-indigo-700 dark:text-indigo-400 ring-1 ring-indigo-500' => $fundLossOnly,
                    'bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' => !$fundLossOnly,
                ])
            >
                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                Fund Loss > 0
            </button>
        </div>

        {{-- Expanded Filters --}}
        <div
            x-show="filtersVisible"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-1"
        >
            <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex flex-wrap gap-4">
                {{-- Severity --}}
                <div class="min-w-[160px]">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 mb-1.5">Severity</label>
                    <div class="flex flex-wrap gap-1">
                        @foreach($severityOptions as $value => $label)
                            <button
                                type="button"
                                wire:click="toggleFilter('severity', '{{ $value }}')"
                                class="px-2 py-1.5 text-[11px] font-medium rounded-md border cursor-pointer transition-all
                                    {{ in_array($value, $severity)
                                        ? 'bg-indigo-500 border-indigo-500 text-white'
                                        : 'bg-gray-50 dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-indigo-300 hover:text-indigo-600 dark:hover:border-indigo-500 dark:hover:text-indigo-400'
                                    }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Type --}}
                <div class="min-w-[140px]">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 mb-1.5">Type</label>
                    <div class="flex flex-wrap gap-1">
                        @foreach($incidentTypeOptions as $value => $label)
                            <button
                                type="button"
                                wire:click="toggleFilter('incidentType', '{{ $value }}')"
                                class="px-2 py-1.5 text-[11px] font-medium rounded-md border cursor-pointer transition-all
                                    {{ in_array($value, $incidentType)
                                        ? 'bg-indigo-500 border-indigo-500 text-white'
                                        : 'bg-gray-50 dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-indigo-300 hover:text-indigo-600 dark:hover:border-indigo-500 dark:hover:text-indigo-400'
                                    }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Fund Status --}}
                <div class="min-w-[160px]">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 mb-1.5">Fund Status</label>
                    <select wire:model.live="fundStatus" aria-label="Fund status" class="w-full px-2.5 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 outline-none cursor-pointer">
                        <option value="">All</option>
                        @foreach($fundStatusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- PIC --}}
                <div class="min-w-[200px]">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 mb-1.5">PIC</label>
                    <div class="flex flex-wrap gap-1 max-h-[80px] overflow-y-auto">
                        @foreach($picOptions as $id => $name)
                            <button
                                type="button"
                                wire:click="toggleFilter('picId', '{{ $id }}')"
                                class="px-2 py-1.5 text-[11px] font-medium rounded-md border cursor-pointer transition-all
                                    {{ in_array((string) $id, array_map('strval', $picId))
                                        ? 'bg-indigo-500 border-indigo-500 text-white'
                                        : 'bg-gray-50 dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-indigo-300 hover:text-indigo-600 dark:hover:border-indigo-500 dark:hover:text-indigo-400'
                                    }}"
                            >
                                {{ $name }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Date Range --}}
                <div class="min-w-[140px]">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 mb-1.5">Date Range</label>
                    <div class="flex gap-2 items-center">
                        <input type="date" wire:model.live="dateFrom" aria-label="Start date" class="px-2 py-1.5 text-[11px] border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 outline-none" />
                        <span class="text-[10px] text-gray-500">to</span>
                        <input type="date" wire:model.live="dateTo" aria-label="End date" class="px-2 py-1.5 text-[11px] border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 outline-none" />
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Board Columns --}}
    <div class="kanban-canvas flex gap-4 overflow-x-auto pb-6 min-h-[calc(100vh-340px)] -mx-1 px-1 rounded-xl">
        @foreach($columns as $column)
            @php
                $colorHex = $statusColors[$column['color']] ?? '#6366f1';
                $columnIncidents = $incidents[$column['value']] ?? collect();
                $totalCount = $totalCounts[$column['value']] ?? 0;
            @endphp
            {{-- Column --}}
            <div
                class="min-w-[300px] max-w-[340px] flex-shrink-0 flex flex-col bg-gray-100 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl overflow-hidden"
                data-status="{{ $column['value'] }}"
            >
                {{-- Column Header --}}
                <div class="flex items-center gap-2 px-3.5 py-2.5 border-b border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900">
                    <div class="w-1 h-4 rounded-full flex-shrink-0" style="background: {{ $colorHex }}"></div>
                    <span class="text-[13px] font-bold flex-1 text-gray-900 dark:text-gray-100">{{ $column['label'] }}</span>
                    <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-gray-200 dark:bg-gray-700 min-w-[24px] text-center text-gray-900 dark:text-gray-100">{{ $totalCount }}</span>
                </div>

                {{-- Column Cards --}}
                <div class="flex-1 min-h-0 overflow-y-auto p-2 flex flex-col gap-2 max-h-[calc(100vh-410px)] min-h-[120px] kanban-scroll">
                    @forelse($columnIncidents as $incident)
                        @php
                            $severityColor = $incident->severity?->color() ?? 'gray';
                            $fundStatusColor = $incident->fund_status?->color();
                        @endphp
                        {{-- Card --}}
                        <div
                            class="kanban-card kanban-card--severity-{{ $severityColor }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-150"
                            data-incident-id="{{ $incident->id }}"
                        >
                            <div class="flex items-center px-1.5 pt-1.5 cursor-grab active:cursor-grabbing" data-drag-handle>
                                <svg class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600" fill="currentColor" viewBox="0 0 16 16"><circle cx="4" cy="3" r="1.5"/><circle cx="12" cy="3" r="1.5"/><circle cx="4" cy="8" r="1.5"/><circle cx="12" cy="8" r="1.5"/><circle cx="4" cy="13" r="1.5"/><circle cx="12" cy="13" r="1.5"/></svg>
                            </div>
                            <div
                                class="block px-4 pb-4 pt-1 cursor-pointer"
                                wire:click="viewIncident({{ $incident->id }})"
                            >
                                {{-- Title (cleaned) --}}
                                @php $displayTitle = \App\Livewire\IncidentKanbanBoard::cleanTitle($incident->title, $incident->incident_date); @endphp
                                <div class="text-sm font-semibold leading-snug mb-1 line-clamp-2 text-gray-900 dark:text-white">{{ $displayTitle }}</div>

                                {{-- ID + Severity --}}
                                <div class="flex items-center gap-1.5 mb-1.5">
                                    <span class="text-[11px] font-mono text-gray-500 dark:text-gray-400 tracking-tight">{{ $incident->no }}</span>
                                    <span class="kanban-badge kanban-badge--{{ $severityColor }}">{{ $incident->severity }}</span>
                                </div>

                                {{-- PIC Avatar or Unassigned + Date + Fund Status --}}
                                <div class="flex items-center gap-2 flex-wrap">
                                    @if($incident->pic)
                                        <span class="inline-flex items-center gap-1.5" title="{{ $incident->pic->name }}">
                                            <span class="kanban-avatar" style="background: {{ \App\Livewire\IncidentKanbanBoard::avatarColor($incident->pic->name) }}">
                                                {{ \App\Livewire\IncidentKanbanBoard::initials($incident->pic->name) }}
                                            </span>
                                        </span>
                                    @else
                                        <span class="kanban-unassigned">
                                            <span class="kanban-unassigned-circle">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                            </span>
                                            Unassigned
                                        </span>
                                    @endif

                                    @if($incident->incident_date)
                                        <span class="text-[10px] text-gray-500 dark:text-gray-400">{{ $incident->incident_date->format('M d') }}</span>
                                    @endif

                                    @if($incident->fund_status && $fundStatusColor)
                                        <span class="kanban-badge kanban-badge--{{ $fundStatusColor }} kanban-badge--sm">{{ $incident->fund_status }}</span>
                                    @endif
                                </div>

                                {{-- Financial Summary --}}
                                @php
                                    $hasFinancial = ($incident->potential_fund_loss > 0 || $incident->fund_loss > 0 || $incident->recovered_fund > 0);
                                @endphp
                                @if($hasFinancial)
                                    <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700/50">
                                        <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                                            @if($incident->potential_fund_loss > 0)
                                                <div>
                                                    <span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-500">Potential</span>
                                                    <p class="text-[11px] font-semibold text-amber-700 dark:text-amber-400 leading-tight">Rp {{ number_format($incident->potential_fund_loss, 0, ',', '.') }}</p>
                                                </div>
                                            @endif
                                            @if($incident->fund_loss > 0)
                                                <div>
                                                    <span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-500">Actual Loss</span>
                                                    <p class="text-[11px] font-semibold text-red-600 dark:text-red-400 leading-tight">Rp {{ number_format($incident->fund_loss, 0, ',', '.') }}</p>
                                                </div>
                                            @endif
                                            @if($incident->recovered_fund > 0)
                                                <div>
                                                    <span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-500">Recovered</span>
                                                    <p class="text-[11px] font-semibold text-green-600 dark:text-green-400 leading-tight">Rp {{ number_format($incident->recovered_fund, 0, ',', '.') }}</p>
                                                </div>
                                            @endif
                                            @php
                                                $recoveryPct = $incident->recovery_percentage;
                                            @endphp
                                            @if($recoveryPct !== null)
                                                <div>
                                                    <span class="text-[9px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-500">Recovery</span>
                                                    <div class="flex items-center gap-1.5">
                                                        <div class="flex-1 h-1 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                                                            <div class="h-full rounded-full transition-all duration-300 {{ $recoveryPct >= 80 ? 'bg-green-500' : ($recoveryPct >= 40 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ min($recoveryPct, 100) }}%"></div>
                                                        </div>
                                                        <span class="text-[11px] font-semibold {{ $recoveryPct >= 80 ? 'text-green-600 dark:text-green-400' : ($recoveryPct >= 40 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">{{ $recoveryPct }}%</span>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>

                            {{-- Accessible move control (keyboard / touch / no-JS fallback to drag) --}}
                            @if(auth()->user()?->can('manage incidents'))
                                <div class="kanban-card__move border-t border-gray-100 dark:border-gray-700/60 px-3 py-1 flex items-center gap-1.5">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 text-gray-300 dark:text-gray-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-5L21 6m0 0l-4.5 4.5M21 6h-13.5" /></svg>
                                    <select
                                        wire:change="updateStatus({{ $incident->id }}, $event.target.value)"
                                        class="kanban-move-select w-full text-[11px] font-medium bg-transparent border-0 outline-none cursor-pointer text-gray-500 dark:text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400 focus:text-indigo-600 dark:focus:text-indigo-400"
                                        aria-label="Move incident {{ $incident->no }} to another status"
                                        onclick="event.stopPropagation()"
                                        title="Move to status"
                                    >
                                        @foreach($columns as $moveCol)
                                            <option value="{{ $moveCol['value'] }}" @selected((string) $moveCol['value'] === (string) ($incident->incident_status?->value))>{{ $moveCol['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center py-8 px-4 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-gray-300 dark:text-gray-600 mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                            <span class="text-xs text-gray-500 dark:text-gray-400">No incidents</span>
                        </div>
                    @endforelse

                    {{-- Load More for Completed column --}}
                    @if($column['value'] === 'Completed' && ! $showAllCompleted && ($totalCounts['Completed'] ?? 0) > 10)
                        <button
                            type="button"
                            wire:click="toggleShowAllCompleted"
                            class="w-full py-2 text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition-colors cursor-pointer"
                        >
                            Show all {{ $totalCounts['Completed'] ?? 0 }} completed
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Slide-Over Panel --}}
    @php $incident = $selectedIncidentId ? $this->selectedIncident : null; @endphp
    <div
        x-data="{ panelOpen: {{ $selectedIncidentId ? 'true' : 'false' }} }"
        x-init="
            $watch('panelOpen', val => { if (!val) $nextTick(() => $wire.closeIncidentPanel()) });
            Livewire.hook('morph.updated', () => { panelOpen = $wire.selectedIncidentId !== null });
        "
    >
        {{-- Overlay --}}
        <div
            x-show="panelOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[var(--z-modal-backdrop)] bg-black/30 backdrop-blur-sm"
            x-on:click="panelOpen = false"
            x-cloak
        ></div>

        {{-- Panel --}}
        <div
            x-show="panelOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="fixed top-0 right-0 z-[var(--z-modal)] h-full w-full max-w-[560px] bg-white dark:bg-gray-900 shadow-2xl overflow-y-auto"
            x-cloak
            x-on:keydown.escape.window="panelOpen = false"
        >
            @if($incident)
                @php
                    $severityColor = $incident->severity?->color() ?? 'gray';
                    $statusColor = $incident->incident_status?->color() ?? 'gray';
                    $fundStatusColor = $incident->fund_status?->color();
                    $severityHex = match($severityColor) {
                        'danger' => '#ef4444', 'warning' => '#f59e0b', 'info' => '#3b82f6', 'success' => '#22c55e', default => '#6b7280'
                    };
                    $timeInStatus = $this->getTimeInStatus($incident);
                    $hasFinancial = ($incident->potential_fund_loss > 0 || $incident->fund_loss > 0 || $incident->recovered_fund > 0);
                    $hasCategories = (!empty($incident->business_category) || !empty($incident->root_cause_category) || !empty($incident->responsible_team));
                @endphp

                {{-- ========== HEADER ========== --}}
                <div class="relative flex border-b border-gray-100 dark:border-gray-800">
                    <div class="flex-1 px-5 pt-5 pb-4">
                        <button
                            type="button"
                            x-on:click="panelOpen = false"
                            class="absolute top-4 right-4 p-1.5 rounded-lg text-gray-500 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors cursor-pointer"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>

                        <div class="pr-8">
                            {{-- Badges --}}
                            <div class="flex items-center gap-1.5 mb-2">
                                <span class="kanban-badge kanban-badge--{{ $severityColor }}">{{ $incident->severity }}</span>
                                <span class="kanban-badge kanban-badge--{{ $statusColor }}">{{ $incident->incident_status }}</span>
                                @if($incident->fund_status && $fundStatusColor)
                                    <span class="kanban-badge kanban-badge--{{ $fundStatusColor }}">{{ $incident->fund_status }}</span>
                                @endif
                            </div>

                            {{-- Title + ID --}}
                            <h2 class="text-base font-bold text-gray-900 dark:text-white leading-snug">{{ $incident->title }}</h2>
                            <p class="text-xs font-mono text-gray-500 dark:text-gray-500 mt-1">{{ $incident->no }}</p>

                            {{-- Meta row --}}
                            <div class="flex items-center gap-3 mt-3 text-xs text-gray-500 dark:text-gray-400">
                                @if($incident->pic)
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="kanban-avatar" style="background: {{ \App\Livewire\IncidentKanbanBoard::avatarColor($incident->pic->name) }}; width: 20px; height: 20px; font-size: 8px;">
                                            {{ \App\Livewire\IncidentKanbanBoard::initials($incident->pic->name) }}
                                        </span>
                                        <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $incident->pic->name }}</span>
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-gray-400">
                                        <span class="w-5 h-5 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-[8px]">?</span>
                                        Unassigned
                                    </span>
                                @endif
                                @if($incident->incident_date)
                                    <span class="text-gray-300 dark:text-gray-600">·</span>
                                    <span>{{ $incident->incident_date->format('M d, Y') }}</span>
                                @endif
                                @if($timeInStatus['text'])
                                    <span class="text-gray-300 dark:text-gray-600">·</span>
                                    <span class="{{ $timeInStatus['overdue'] ? 'text-red-600 dark:text-red-400 font-bold' : '' }}">{{ $timeInStatus['text'] }}</span>
                                    @if($timeInStatus['overdue'])
                                        <span class="text-[9px] font-bold bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400 px-1.5 py-0.5 rounded-full">SLA</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ========== BODY ========== --}}
                <div class="p-5 space-y-5">

                    {{-- Metadata Grid --}}
                    <div class="grid grid-cols-[88px_1fr] gap-x-4 gap-y-2.5 text-sm">
                        {{-- Incident Type --}}
                        @if($incident->classification || $incident->incident_type)
                            <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 self-start pt-0.5">Incident</span>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                @if($incident->classification)
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $incident->classification }}</span>
                                @endif
                                @if($incident->incident_type)
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-blue-50 dark:bg-blue-500/15 text-blue-600 dark:text-blue-300">{{ $incident->incident_type }}</span>
                                @endif
                                @if($incident->incidentType)
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300">{{ $incident->incidentType->name }}</span>
                                @endif
                                @if($incident->incident_category)
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-amber-50 dark:bg-amber-500/15 text-amber-600 dark:text-amber-300">{{ $incident->incident_category }}</span>
                                @endif
                            </div>
                        @endif

                        {{-- Labels --}}
                        @if($incident->labels->isNotEmpty())
                            <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 self-start pt-0.5">Labels</span>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                @foreach($incident->labels as $label)
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ $label->name }}</span>
                                @endforeach
                            </div>
                        @endif

                        {{-- Business Category --}}
                        @if(!empty($incident->business_category))
                            <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 self-start pt-0.5">Business</span>
                            <div class="flex flex-wrap gap-1">
                                @foreach($incident->business_category as $cat)
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300">{{ $cat }}</span>
                                @endforeach
                            </div>
                        @endif

                        {{-- Root Cause --}}
                        @if(!empty($incident->root_cause_category))
                            <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 self-start pt-0.5">Root Cause</span>
                            <div class="flex flex-wrap gap-1">
                                @foreach($incident->root_cause_category as $cat)
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-amber-50 dark:bg-amber-500/15 text-amber-600 dark:text-amber-300">{{ $cat }}</span>
                                @endforeach
                            </div>
                        @endif

                        {{-- Team --}}
                        @if(!empty($incident->responsible_team))
                            <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500 self-start pt-0.5">Team</span>
                            <div class="flex flex-wrap gap-1">
                                @foreach($incident->responsible_team as $team)
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-teal-50 dark:bg-teal-500/15 text-teal-600 dark:text-teal-300">{{ $team }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Financial Impact --}}
                    @if($hasFinancial)
                        <div class="space-y-2.5">
                            <div class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-500">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" /></svg>
                                Financial Impact
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                @if($incident->potential_fund_loss > 0)
                                    <div class="p-2.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 border border-amber-100 dark:border-amber-500/20">
                                        <span class="text-[9px] font-bold uppercase tracking-wider text-amber-500 dark:text-amber-400">Potential</span>
                                        <p class="text-xs font-bold text-amber-800 dark:text-amber-300 mt-0.5">Rp {{ number_format($incident->potential_fund_loss, 0, ',', '.') }}</p>
                                    </div>
                                @endif
                                @if($incident->fund_loss > 0)
                                    <div class="p-2.5 rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20">
                                        <span class="text-[9px] font-bold uppercase tracking-wider text-red-500 dark:text-red-400">Actual Loss</span>
                                        <p class="text-xs font-bold text-red-800 dark:text-red-300 mt-0.5">Rp {{ number_format($incident->fund_loss, 0, ',', '.') }}</p>
                                    </div>
                                @endif
                                @if($incident->recovered_fund > 0)
                                    <div class="p-2.5 rounded-lg bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20">
                                        <span class="text-[9px] font-bold uppercase tracking-wider text-green-500 dark:text-green-400">Recovered</span>
                                        <p class="text-xs font-bold text-green-800 dark:text-green-300 mt-0.5">Rp {{ number_format($incident->recovered_fund, 0, ',', '.') }}</p>
                                    </div>
                                @endif
                            </div>
                            @php $recoveryPct = $incident->recovery_percentage; @endphp
                            @if($recoveryPct !== null)
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-500 {{ $recoveryPct >= 80 ? 'bg-green-500' : ($recoveryPct >= 40 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ min($recoveryPct, 100) }}%"></div>
                                    </div>
                                    <span class="text-[11px] font-bold {{ $recoveryPct >= 80 ? 'text-green-600 dark:text-green-400' : ($recoveryPct >= 40 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">{{ $recoveryPct }}% recovered</span>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- ========== STICKY FOOTER ========== --}}
                <div class="sticky bottom-0 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 px-5 py-3 flex gap-2">
                    <a
                        href="{{ url('/admin/incidents/' . $incident->id) }}"
                        target="_blank"
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors flex-1"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                        Open Full Page
                    </a>
                    @if(auth()->user()?->can('manage incidents'))
                        <a
                            href="{{ url('/admin/incidents/' . $incident->id . '/edit') }}"
                            class="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors flex-1"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                            Edit
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- SortableJS + Alpine Logic --}}
    <script>
        function kanbanBoard() {
            return {
                filtersVisible: {{ $filtersVisible ? 'true' : 'false' }},
                sortableInstances: [],
                componentId: null,

                init() {
                    this.componentId = this.$wire.id;
                    this.$nextTick(() => this.initSortable());

                    Livewire.hook('morph.updated', ({ component }) => {
                        if (component.id === this.componentId) {
                            this.$nextTick(() => this.initSortable());
                        }
                    });
                },

                initSortable() {
                    this.sortableInstances.forEach(s => s.destroy());
                    this.sortableInstances = [];

                    var canManage = {{ auth()->user()?->can('manage incidents') ? 'true' : 'false' }};

                    // SortableJS supports touch natively. The delay makes a quick swipe
                    // scroll the column and a long-press start a drag — this is what lets
                    // phones/tablets move cards (previously disabled entirely).
                    if (!canManage || typeof Sortable === 'undefined') return;

                    var self = this;
                    this.$el.querySelectorAll('.kanban-scroll').forEach(function(el) {
                        var instance = new Sortable(el, {
                            group: 'incidents',
                            animation: 150,
                            ghostClass: 'kanban-ghost',
                            dragClass: 'kanban-drag',
                            handle: '[data-drag-handle]',
                            delay: 120,
                            delayOnTouchOnly: true,
                            touchStartThreshold: 5,
                            onEnd: function(evt) {
                                var incidentId = parseInt(evt.item.dataset.incidentId);
                                var newStatus = evt.to.closest('[data-status]').dataset.status;

                                if (!incidentId || !newStatus) return;

                                var originalParent = evt.from;
                                var originalIndex = evt.oldIndex;

                                self.$wire.updateStatus(incidentId, newStatus).catch(function() {
                                    originalParent.insertBefore(evt.item, originalParent.children[originalIndex] || null);
                                });
                            },
                        });
                        self.sortableInstances.push(instance);
                    });
                },

                moveCard(event, incidentId, currentStatus) {
                    var newStatus = event.target.value;
                    if (!newStatus || newStatus === currentStatus) return;

                    this.$wire.updateStatus(incidentId, newStatus);
                    event.target.value = '';
                },
            };
        }
    </script>
</div>
