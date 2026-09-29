<x-filament-panels::page>
    <section class="mb-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h2 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Flow</h2>
        <div class="space-y-4">
            @if ($mermaid !== '')
                <pre class="mermaid-source" data-diagram="{{ $mermaid }}" hidden></pre>
                <div class="workflow-diagram overflow-x-auto rounded-xl bg-gray-50 p-4 dark:bg-gray-950/50"></div>
            @else
                <p class="text-sm text-gray-500">No steps yet — edit the workflow to add agents.</p>
            @endif
        </div>
    </section>

    <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h2 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Steps</h2>
        <div class="space-y-2">
            @foreach ($workflowSteps as $step)
                <div class="flex items-center gap-3 rounded-xl bg-gray-50 p-3 dark:bg-gray-950/50">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xs font-semibold text-white">{{ $loop->iteration }}</span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $step->agent?->name ?? '(deleted agent)' }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $loop->first ? 'Starts the workflow' : 'Runs after '.$workflowSteps[$loop->index - 1]->agent?->name.' completes' }}
                            @if ($step->agent && $step->agent->frequency->value !== 'Manual')
                                — {{ $step->agent->frequency->value }} schedule
                            @endif
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    @push('scripts')
        @vite(['resources/js/mermaid.js'])
        <script>
            window.addEventListener('load', async () => {
                const source = document.querySelector('.mermaid-source');
                const target = document.querySelector('.workflow-diagram');
                if (!source || !target) return;

                const diagram = source.dataset.diagram;
                const tryRender = async () => {
                    if (typeof mermaid === 'undefined') { setTimeout(tryRender, 120); return; }
                    try {
                        const { svg } = await mermaid.render('workflow-graph', diagram);
                        target.innerHTML = svg;
                    } catch (e) {
                        // Escaped fallback — never render broken HTML.
                        target.innerHTML = '<pre class="text-xs whitespace-pre-wrap">' +
                            diagram.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</pre>';
                    }
                };
                tryRender();
            });
        </script>
    @endpush
</x-filament-panels::page>
