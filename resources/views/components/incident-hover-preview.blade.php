@props(['incident'])

@php
    use App\Enums\FundStatus;
    use App\Enums\IncidentStatus;
    use App\Enums\Severity;

    $severityColor = $incident->severity?->color() ?? 'gray';
    $statusColor = $incident->incident_status?->color() ?? 'gray';
    $fundStatusColor = $incident->fund_status?->color();
@endphp

<div
    x-data="{
        show: false,
        top: 0,
        left: 0,
        hideTimeout: null,

        open() {
            clearTimeout(this.hideTimeout);
            const rect = this.\$refs.trigger.getBoundingClientRect();
            const viewportHeight = window.innerHeight;
            const estimatedHeight = 320;

            if (rect.bottom + estimatedHeight + 10 > viewportHeight) {
                this.top = Math.max(8, rect.top - estimatedHeight - 10);
            } else {
                this.top = rect.bottom + 10;
            }
            this.left = Math.max(8, Math.min(rect.left, window.innerWidth - 424));
            this.show = true;
        },

        close() {
            this.hideTimeout = setTimeout(() => { this.show = false; }, 200);
        },

        cancelClose() {
            clearTimeout(this.hideTimeout);
        }
    }"
    class="inline-block"
>
    <span
        x-ref="trigger"
        tabindex="0"
        role="button"
        aria-label="Preview incident {{ $incident->no }}"
        @mouseenter="open()"
        @mouseleave="close()"
        @focus="open()"
        @blur="close()"
        @click="open()"
        class="cursor-pointer text-sm text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 font-medium hover:underline decoration-primary-300 underline-offset-2 transition-colors rounded-sm focus:outline-none focus:ring-2 focus:ring-primary-500/40"
    >
        {{ Illuminate\Support\Str::limit($incident->title, 30) }}
    </span>

    <template x-teleport="body">
        <div
            x-show="show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-2"
            @mouseenter="cancelClose()"
            @mouseleave="close()"
            x-effect="if (show) { $el.style.top = top + 'px'; $el.style.left = left + 'px'; }"
            class="fixed z-[var(--z-tooltip)] w-[400px]"
            style="display: none;"
            x-cloak
        >
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl ring-1 ring-gray-200 dark:ring-gray-700 overflow-hidden">

                {{-- Header with ID + badges --}}
                <div class="flex items-center gap-2 px-4 py-2.5 bg-gray-50 dark:bg-gray-900/50 border-b border-gray-100 dark:border-gray-700">
                    <span class="text-xs font-mono text-gray-500 dark:text-gray-400">{{ $incident->no }}</span>
                    <x-filament::badge :color="$severityColor" size="sm">{{ $incident->severity }}</x-filament::badge>
                    <x-filament::badge :color="$statusColor" size="sm">{{ $incident->incident_status }}</x-filament::badge>
                    @if($incident->fund_status)
                        <x-filament::badge :color="$fundStatusColor" size="sm">{{ $incident->fund_status }}</x-filament::badge>
                    @endif
                </div>

                {{-- Body --}}
                <div class="px-4 py-3 space-y-3">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white leading-tight">
                        {{ $incident->title }}
                    </h4>

                    @if($incident->summary)
                        <p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed line-clamp-3">
                            {{ $incident->summary }}
                        </p>
                    @endif

                    {{-- Financial Impact --}}
                    @if($incident->potential_fund_loss > 0 || $incident->fund_loss > 0 || $incident->recovered_fund > 0)
                        <div class="bg-gray-50 dark:bg-gray-900/50 rounded-lg p-3 space-y-1.5">
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Financial Impact</p>
                            @if($incident->potential_fund_loss > 0)
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Potential Loss</span>
                                    <span class="font-medium text-gray-900 dark:text-white">Rp {{ number_format($incident->potential_fund_loss, 0, ',', '.') }}</span>
                                </div>
                            @endif
                            @if($incident->fund_loss > 0)
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Fund Loss</span>
                                    <span class="font-medium text-red-600 dark:text-red-400">Rp {{ number_format($incident->fund_loss, 0, ',', '.') }}</span>
                                </div>
                            @endif
                            @if($incident->recovered_fund > 0)
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Recovered</span>
                                    <span class="font-medium text-green-600 dark:text-green-400">Rp {{ number_format($incident->recovered_fund, 0, ',', '.') }}</span>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Meta Info --}}
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        @if($incident->pic)
                            <span class="flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-user" class="w-3.5 h-3.5" />
                                {{ $incident->pic->name }}
                            </span>
                        @endif
                        @if($incident->incident_date)
                            <span class="flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-calendar" class="w-3.5 h-3.5" />
                                {{ $incident->incident_date->format('M d, Y') }}
                            </span>
                        @endif
                        @if($incident->incidentType)
                            <span class="flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-tag" class="w-3.5 h-3.5" />
                                {{ $incident->incidentType->name }}
                            </span>
                        @endif
                        @if($incident->root_cause)
                            <span class="flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-magnifying-glass" class="w-3.5 h-3.5" />
                                {{ Illuminate\Support\Str::limit($incident->root_cause, 40) }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Footer --}}
                <div class="px-4 py-2.5 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    <a href="{{ url('/admin/incidents/' . $incident->id) }}" class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 flex items-center gap-1 transition-colors">
                        View full details
                        <x-filament::icon icon="heroicon-o-arrow-right" class="w-3 h-3" />
                    </a>
                </div>
            </div>
        </div>
    </template>
</div>
