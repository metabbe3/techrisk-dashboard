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
    ]));
@endphp

@once
@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
@endpush
@endonce

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
                    wire:model.live.debounce.300ms="searchQuery"
                    placeholder="Search ID or title..."
                    class="w-full px-3 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 placeholder-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-500/20 outline-none transition-colors"
                />
            </div>

            <select wire:model.live="quickPeriod" class="px-2.5 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 outline-none cursor-pointer focus:border-indigo-400">
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
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1.5">Severity</label>
                    <div class="flex flex-wrap gap-1">
                        @foreach($severityOptions as $value => $label)
                            <button
                                type="button"
                                wire:click="toggleFilter('severity', '{{ $value }}')"
                                class="px-2 py-1 text-[11px] font-medium rounded-md border cursor-pointer transition-all
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
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1.5">Type</label>
                    <div class="flex flex-wrap gap-1">
                        @foreach($incidentTypeOptions as $value => $label)
                            <button
                                type="button"
                                wire:click="toggleFilter('incidentType', '{{ $value }}')"
                                class="px-2 py-1 text-[11px] font-medium rounded-md border cursor-pointer transition-all
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
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1.5">Fund Status</label>
                    <select wire:model.live="fundStatus" class="w-full px-2.5 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 outline-none cursor-pointer">
                        <option value="">All</option>
                        @foreach($fundStatusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- PIC --}}
                <div class="min-w-[200px]">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1.5">PIC</label>
                    <div class="flex flex-wrap gap-1 max-h-[80px] overflow-y-auto">
                        @foreach($picOptions as $id => $name)
                            <button
                                type="button"
                                wire:click="toggleFilter('picId', '{{ $id }}')"
                                class="px-2 py-1 text-[11px] font-medium rounded-md border cursor-pointer transition-all
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
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1.5">Date Range</label>
                    <div class="flex gap-2 items-center">
                        <input type="date" wire:model.live.debounce.500ms="dateFrom" class="px-2 py-1 text-[11px] border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 outline-none" />
                        <span class="text-[10px] text-gray-400">to</span>
                        <input type="date" wire:model.live.debounce.500ms="dateTo" class="px-2 py-1 text-[11px] border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 outline-none" />
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Board Columns --}}
    <div class="flex gap-4 overflow-x-auto pb-4 min-h-[calc(100vh-340px)]">
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
                <div class="flex-1 overflow-y-auto p-2 flex flex-col gap-2 max-h-[calc(100vh-410px)] min-h-[120px] kanban-scroll">
                    @forelse($columnIncidents as $incident)
                        @php
                            $severityColor = Severity::tryFrom($incident->severity)?->color() ?? 'gray';
                            $fundStatusColor = $incident->fund_status ? FundStatus::tryFrom($incident->fund_status)?->color() : null;
                        @endphp
                        {{-- Card --}}
                        <div
                            class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-3 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-150 cursor-grab active:cursor-grabbing"
                            data-incident-id="{{ $incident->id }}"
                        >
                            {{-- Title (PRIMARY — most prominent) --}}
                            <div class="text-sm font-semibold leading-snug mb-1 line-clamp-2 text-gray-900 dark:text-white">{{ $incident->title }}</div>

                            {{-- ID + Severity (SECONDARY — below title) --}}
                            <div class="flex items-center gap-1.5 mb-1.5">
                                <span class="text-[11px] font-mono text-gray-500 dark:text-gray-400 tracking-tight">{{ $incident->no }}</span>
                                <span class="kanban-badge kanban-badge--{{ $severityColor }}">{{ $incident->severity }}</span>
                            </div>

                            {{-- Meta --}}
                            <div class="flex flex-wrap gap-x-3 gap-y-0.5">
                                @if($incident->pic)
                                    <span class="inline-flex items-center gap-1 text-[11px] text-gray-600 dark:text-gray-300">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                                        {{ $incident->pic->name }}
                                    </span>
                                @endif
                                @if($incident->incident_date)
                                    <span class="inline-flex items-center gap-1 text-[11px] text-gray-600 dark:text-gray-300">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                                        {{ $incident->incident_date->format('M d, Y') }}
                                    </span>
                                @endif
                            </div>

                            {{-- Fund Status Badge --}}
                            @if($incident->fund_status && $fundStatusColor)
                                <div class="mt-1.5">
                                    <span class="kanban-badge kanban-badge--{{ $fundStatusColor }} kanban-badge--sm">{{ $incident->fund_status }}</span>
                                </div>
                            @endif

                            {{-- Financial Summary --}}
                            @php($hasFinancial = ($incident->potential_fund_loss > 0 || $incident->fund_loss > 0 || $incident->recovered_fund > 0))
                            @if($hasFinancial)
                                <div class="mt-2 p-2 rounded-md bg-gray-50 dark:bg-gray-900/40 border border-gray-100 dark:border-gray-700/50">
                                    <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                                        @if($incident->potential_fund_loss > 0)
                                            <div>
                                                <span class="text-[9px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Potential</span>
                                                <p class="text-[11px] font-semibold text-amber-700 dark:text-amber-400 leading-tight">Rp {{ number_format($incident->potential_fund_loss, 0, ',', '.') }}</p>
                                            </div>
                                        @endif
                                        @if($incident->fund_loss > 0)
                                            <div>
                                                <span class="text-[9px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Actual Loss</span>
                                                <p class="text-[11px] font-semibold text-red-600 dark:text-red-400 leading-tight">Rp {{ number_format($incident->fund_loss, 0, ',', '.') }}</p>
                                            </div>
                                        @endif
                                        @if($incident->recovered_fund > 0)
                                            <div>
                                                <span class="text-[9px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Recovered</span>
                                                <p class="text-[11px] font-semibold text-green-600 dark:text-green-400 leading-tight">Rp {{ number_format($incident->recovered_fund, 0, ',', '.') }}</p>
                                            </div>
                                        @endif
                                        @php($recoveryPct = $incident->recovery_percentage)
                                        @if($recoveryPct !== null)
                                            <div>
                                                <span class="text-[9px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Recovery</span>
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

                            {{-- Actions --}}
                            <div class="flex items-center gap-1.5 mt-2 pt-2 border-t border-gray-200 dark:border-gray-600">
                                @if(auth()->user()?->can('manage incidents'))
                                    <div class="flex-1">
                                        <select
                                            class="w-full px-2 py-1 text-[11px] border border-gray-300 dark:border-gray-600 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 outline-none cursor-pointer focus:border-indigo-400 transition-colors"
                                            wire:ignore
                                            x-on:change="moveCard($event, {{ $incident->id }}, '{{ $column['value'] }}')"
                                        >
                                            <option value="" disabled selected>Move to...</option>
                                            @foreach($columns as $moveCol)
                                                @if($moveCol['value'] !== $column['value'])
                                                    <option value="{{ $moveCol['value'] }}">{{ $moveCol['label'] }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                                <a
                                    href="{{ url('/admin/incidents/' . $incident->id) }}"
                                    class="inline-flex items-center justify-center w-7 h-7 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors flex-shrink-0"
                                    title="View details"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center py-8 px-4 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-gray-300 dark:text-gray-600 mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                            <span class="text-xs text-gray-500 dark:text-gray-400">No incidents</span>
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
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

                    var isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
                    var canManage = {{ auth()->user()?->can('manage incidents') ? 'true' : 'false' }};

                    if (isTouchDevice || !canManage || typeof Sortable === 'undefined') return;

                    var self = this;
                    this.$el.querySelectorAll('.kanban-scroll').forEach(function(el) {
                        var instance = new Sortable(el, {
                            group: 'incidents',
                            animation: 150,
                            ghostClass: 'kanban-ghost',
                            dragClass: 'rotate-2 shadow-xl scale-[1.03]',
                            handle: '[data-incident-id]',
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
