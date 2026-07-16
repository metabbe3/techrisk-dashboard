<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between">
                <span>Risk Heat Matrix</span>
                <span class="text-xs font-normal text-gray-500">Likelihood &times; Impact &mdash; {{ $totalIncidents }} incidents</span>
            </div>
        </x-slot>

        <div class="overflow-x-auto">
            <div class="min-w-[520px]">
                <div class="flex items-end">
                    <div class="flex flex-col items-center mr-1" style="writing-mode: vertical-rl; transform: rotate(180deg);">
                        <span class="text-xs font-medium text-gray-500 tracking-wider pb-2">LIKELIHOOD &rarr;</span>
                    </div>

                    <div class="flex-1">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr>
                                    <th class="w-28 p-1"></th>
                                    @for ($impact = 1; $impact <= 5; $impact++)
                                        <th class="p-1 text-center">
                                            <span class="text-[10px] font-medium text-gray-500 leading-tight block">
                                                {{ ['Negligible', 'Minor', 'Moderate', 'Major', 'Catastrophic'][$impact - 1] }}
                                            </span>
                                        </th>
                                    @endfor
                                </tr>
                            </thead>
                            <tbody>
                                @for ($likelihood = 5; $likelihood >= 1; $likelihood--)
                                    <tr>
                                        <td class="p-1 text-right pr-2">
                                            <span class="text-[10px] font-medium text-gray-500">
                                                {{ ['Rare', 'Unlikely', 'Possible', 'Likely', 'Almost Certain'][$likelihood - 1] }}
                                            </span>
                                        </td>
                                        @for ($impact = 1; $impact <= 5; $impact++)
                                            @php
                                                $cell = $matrix[$likelihood][$impact] ?? ['count' => 0, 'ids' => []];
                                                $count = $cell['count'];
                                                $colorClass = $this->getCellColor($likelihood, $impact);
                                                $riskLabel = $this->getRiskLabel($likelihood, $impact);
                                                $idsParam = $count > 0 ? implode(',', $cell['ids']) : '';
                                            @endphp
                                            <td class="p-0.5">
                                                @if ($count > 0)
                                                    <a href="{{ route('filament.admin.resources.incidents.index') }}?ids={{ $idsParam }}"
                                                       class="block rounded-md px-2 py-3 text-center transition-all hover:opacity-80 hover:scale-105 {{ $colorClass }}"
                                                       aria-label="{{ $riskLabel }} risk: {{ $count }} incident{{ $count > 1 ? 's' : '' }}"
                                                       title="{{ $riskLabel }}: {{ $count }} incident{{ $count > 1 ? 's' : '' }}">
                                                        <span class="text-lg font-bold">{{ $count }}</span>
                                                    </a>
                                                @else
                                                    <div class="block rounded-md px-2 py-3 text-center bg-gray-50 text-gray-500">
                                                        <span class="text-lg font-medium">&ndash;</span>
                                                    </div>
                                                @endif
                                            </td>
                                        @endfor
                                    </tr>
                                @endfor
                            </tbody>
                        </table>

                        <div class="flex items-center justify-center mt-3 gap-4 text-xs text-gray-500">
                            <span>IMPACT &rarr;</span>
                        </div>

                        <div class="flex items-center justify-center mt-3 gap-3">
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded bg-green-400"></div>
                                <span class="text-[10px] text-gray-500">Low</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded bg-yellow-400"></div>
                                <span class="text-[10px] text-gray-500">Medium</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded bg-orange-500"></div>
                                <span class="text-[10px] text-gray-500">High</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded bg-red-500"></div>
                                <span class="text-[10px] text-gray-500">Critical</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
