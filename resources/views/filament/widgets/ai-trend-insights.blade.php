<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <span class="flex items-center gap-2">
                <svg class="ai-trends-heading-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                Trend Insights
            </span>
        </x-slot>

        <x-slot name="headerActions">
            <div x-data="{ loading: false, open: false }" class="ai-trends-actions">
                @if($trGeneratedAt)
                    <span class="ai-trends-ts">
                        @if($trCached) Cached &middot; @else Generated &middot; @endif
                        {{ \Carbon\Carbon::parse($trGeneratedAt)->diffForHumans(\Carbon\Carbon::now(), ['short' => true]) }}
                    </span>
                    <button type="button" x-on:click="loading = true; $wire.analyze(null, true).then(() => loading = false).catch(() => loading = false)" x-bind:disabled="loading" class="ai-trends-refresh" title="Refresh analysis">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                @endif

                @if($hasMultipleModels)
                    <div class="ai-trends-btn-wrap">
                        <button type="button" x-on:click="open = !open" x-bind:disabled="loading" class="ai-trends-btn">
                            <span x-show="!loading" style="display:inline-flex;align-items:center;gap:5px;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                                Analyze ({{ $trSelectedModel }})
                            </span>
                            <span x-show="loading" style="display:inline-flex;align-items:center;gap:5px;">
                                <svg class="ai-trends-spin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                Analyzing...
                            </span>
                        </button>
                        <div x-show="open" x-on:click.away="open = false" x-transition x-cloak class="ai-trends-mp">
                            <div class="ai-trends-mp__head">Model</div>
                            @foreach($models as $modelId => $modelName)
                                <button type="button" x-on:click="loading = true; open = false; $wire.analyze('{{ $modelId }}').then(() => loading = false).catch(() => loading = false)" class="ai-trends-mp__item {{ $trSelectedModel === $modelId ? 'ai-trends-mp__item--active' : '' }}">
                                    {{ $modelName }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    <button type="button" x-on:click="loading = true; $wire.analyze().then(() => loading = false).catch(() => loading = false)" x-bind:disabled="loading" class="ai-trends-btn">
                        <span x-show="!loading" style="display:inline-flex;align-items:center;gap:5px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                            Analyze Trends
                        </span>
                        <span x-show="loading" style="display:inline-flex;align-items:center;gap:5px;">
                            <svg class="ai-trends-spin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                            Analyzing...
                        </span>
                    </button>
                @endif
            </div>
        </x-slot>

        <div x-data="{ loading: false, open: false }">
        {{-- Loading skeleton --}}
        <div x-show="loading" x-cloak class="ai-trends-skel">
            <div class="ai-trends-skel__section">
                <div class="ai-trends-skel__bar" style="width:35%;"></div>
                <div class="ai-trends-skel__line" style="width:90%;"></div>
                <div class="ai-trends-skel__line" style="width:75%;"></div>
                <div class="ai-trends-skel__line" style="width:82%;"></div>
            </div>
            <div class="ai-trends-skel__section">
                <div class="ai-trends-skel__bar" style="width:42%;"></div>
                <div class="ai-trends-skel__line" style="width:85%;"></div>
                <div class="ai-trends-skel__line" style="width:68%;"></div>
            </div>
            <div class="ai-trends-skel__section">
                <div class="ai-trends-skel__bar" style="width:30%;"></div>
                <div class="ai-trends-skel__line" style="width:92%;"></div>
                <div class="ai-trends-skel__line" style="width:78%;"></div>
            </div>
            <div class="ai-trends-skel__section">
                <div class="ai-trends-skel__bar" style="width:45%;"></div>
                <div class="ai-trends-skel__line" style="width:88%;"></div>
                <div class="ai-trends-skel__line" style="width:70%;"></div>
            </div>
        </div>

        {{-- Error --}}
        @if($trError)
            <div x-show="!loading" class="ai-trends-error">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                <span>{{ $trError }}</span>
                <button type="button" x-on:click="loading = true; $wire.analyze().then(() => loading = false).catch(() => loading = false)" class="ai-trends-error__retry">Retry</button>
            </div>
        @endif

        {{-- Results --}}
        @if($trOpen && $trResults)
            <div x-show="!loading" class="ai-trends-results">
                @if(!empty($trResults['trends']))
                    <div class="ai-trends-card ai-trends-card--trends">
                        <div class="ai-trends-card__head">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            <span>Key Trends</span>
                        </div>
                        <ul class="ai-trends-card__list">
                            @foreach($trResults['trends'] as $i => $trend)
                                <li class="ai-trends-item" style="animation-delay:{{ $i * 80 }}ms">{{ $trend }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(!empty($trResults['recurring_issues']))
                    <div class="ai-trends-card ai-trends-card--recurring">
                        <div class="ai-trends-card__head">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <span>Recurring Issues</span>
                        </div>
                        <ul class="ai-trends-card__list">
                            @foreach($trResults['recurring_issues'] as $i => $issue)
                                <li class="ai-trends-item" style="animation-delay:{{ $i * 80 }}ms">{{ $issue }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(!empty($trResults['anomalies']))
                    <div class="ai-trends-card ai-trends-card--anomalies">
                        <div class="ai-trends-card__head">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            <span>Anomalies</span>
                        </div>
                        <ul class="ai-trends-card__list">
                            @foreach($trResults['anomalies'] as $i => $anomaly)
                                <li class="ai-trends-item" style="animation-delay:{{ $i * 80 }}ms">{{ $anomaly }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(!empty($trResults['recommendations']))
                    <div class="ai-trends-card ai-trends-card--recs">
                        <div class="ai-trends-card__head">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5Z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                            <span>Recommendations</span>
                        </div>
                        <ul class="ai-trends-card__list">
                            @foreach($trResults['recommendations'] as $i => $rec)
                                <li class="ai-trends-item" style="animation-delay:{{ $i * 80 }}ms">{{ $rec }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        {{-- Empty state --}}
        @if(!$trOpen && !$trError)
            <div x-show="!loading" class="ai-trends-empty">
                <svg class="ai-trends-empty__icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>
                </svg>
                <div class="ai-trends-empty__title">AI Trend Analysis</div>
                <div class="ai-trends-empty__desc">Analyze your incident data to discover patterns, recurring issues, and actionable insights.</div>
            </div>
        @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
