<x-filament-panels::page>
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
@endpush

<script src="{{ asset('js/war-room/utils.js') }}"></script>
<script src="{{ asset('js/war-room/agents.js') }}"></script>
<script src="{{ asset('js/war-room/session.js') }}"></script>
<script src="{{ asset('js/war-room/form.js') }}"></script>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('warRoom', () => {
        const routes = {
            agents: '{{ route("war-room.agents") }}',
            sessions: '{{ route("war-room.sessions") }}',
            create: '{{ route("war-room.create") }}',
            incidentSearch: '{{ route("war-room.incident-search") }}',
            estimateTokens: '{{ route("war-room.estimate-tokens") }}',
            templatesIndex: '{{ route("war-room.templates.index") }}',
            templatesStore: '{{ route("war-room.templates.store") }}',
            templatesUpdate: '{{ route("war-room.templates.update", ["id" => "__ID__"]) }}',
            templatesDestroy: '{{ route("war-room.templates.destroy", ["id" => "__ID__"]) }}',
        };

        const routeForBound = (name, ...ids) => self.routeFor(name, ...ids);
        const sessionModule = window.WarRoomSession(routes, routeForBound);
        const formModule = window.WarRoomForm(routes, routeForBound);
        const agentsModule = window.WarRoomAgents();

        const self = {
            routeFor(name, ...ids) {
                let url = '{{ route("war-room.show", ["id" => "PLACEHOLDER"]) }}'.replace('PLACEHOLDER', ids[0] || '');
                if (name === 'agents') return routes.agents;
                if (name === 'sessions' || name === 'create') return routes.create;
                if (name === 'sessionsList') return routes.sessions;
                if (name === 'incidentSearch') return routes.incidentSearch;
                if (name === 'estimateTokens') return routes.estimateTokens;
                const map = {
                    'show': '{{ route("war-room.show", ["id" => "__ID__"]) }}',
                    'poll': '{{ route("war-room.poll", ["id" => "__ID__"]) }}',
                    'retry': '{{ route("war-room.retry", ["id" => "__ID__"]) }}',
                    'retryAgent': '{{ route("war-room.retry-agent", ["id" => "__ID__", "messageId" => "__MSG__"]) }}',
                    'retryReport': '{{ route("war-room.retry-report", ["id" => "__ID__"]) }}',
                    'regenerateReport': '{{ route("war-room.regenerate-report", ["id" => "__ID__"]) }}',
                    'reanalyze': '{{ route("war-room.reanalyze", ["id" => "__ID__"]) }}',
                    'delete': '{{ route("war-room.delete", ["id" => "__ID__"]) }}',
                    'exportPdf': '{{ route("war-room.export-pdf", ["id" => "__ID__"]) }}',
                    'exportMarkdown': '{{ route("war-room.export-markdown", ["id" => "__ID__"]) }}',
                    'exportJson': '{{ route("war-room.export-json", ["id" => "__ID__"]) }}',
                };
                url = map[name] || '';
                url = url.replace('__ID__', ids[0] || '');
                if (ids[1]) url = url.replace('__MSG__', ids[1]);
                return url;
            },

            // Reactive state
            showSidebar: false,
            showCreateForm: true,
            showReport: false,
            showReanalyzeModal: false,
            reanalyzeInstructions: '',
            reanalyzeModel: '',
            reanalyzeModeratorModel: '',
            reanalyzeAgents: [],
            reanalyzeDeepAnalysis: true,
            reanalyzing: false,
            creating: false,
            sessions: [],
            activeSession: null,
            availableAgents: [],
            models: {!! json_encode($models) !!},
            defaultModel: {!! json_encode($defaultModel) !!},

            incidentSearch: '',
            incidentResults: [],
            selectedIncidents: [],
            selectedAgents: [],
            config: { maxRounds: 2, model: '', moderatorModel: '', enableWebSearch: false, deepAnalysis: true, userInstructions: '' },
            createTab: 'incident',
            tokenEstimate: null,
            tokenDebounce: null,
            pollInterval: null,
            sessionSearch: '',
            sessionStatusFilter: '',
            templates: [],
            templateName: '',

            // Delegated helpers from utils
            toolLabel(name) { return window.WarRoomUtils.toolLabel(name); },
            formatDate(dateStr) { return window.WarRoomUtils.formatDate(dateStr); },
            getHeaders(withContentType) { return window.WarRoomUtils.getHeaders(withContentType); },

            async init() {
                window.WarRoomUtils.initMermaid();
                await this.loadAgents();
                await this.loadSessions();
                this.loadTemplates();
                const params = new URLSearchParams(window.location.search);
                const sessionId = params.get('session');
                if (sessionId) {
                    await this.loadSession(sessionId);
                }
            },

            // Spread methods from modules
            ...sessionModule,
            ...formModule,
            ...agentsModule,
        };

        // Restore getter descriptors that spread operator flattened into static values
        for (const [key, desc] of Object.entries(Object.getOwnPropertyDescriptors(sessionModule))) {
            if (desc.get) Object.defineProperty(self, key, desc);
        }

        return self;
    });
});
</script>

<div x-data="warRoom" class="df-app">
    {{-- Mobile overlay --}}
    <div class="df-mobile-overlay" :class="{ 'df-mobile-overlay--visible': showSidebar }" @click="showSidebar = false"></div>

    {{-- Sidebar --}}
    <aside class="df-sidebar" :class="{ 'df-sidebar--open': showSidebar }">
        <div class="df-sidebar__header">
            <div class="df-sidebar__title-row">
                <svg class="df-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                </svg>
                <h3 class="df-sidebar__heading">Sessions</h3>
            </div>
            <button class="df-btn df-btn--primary df-btn--sm" @click="showCreateForm = true; activeSession = null; showSidebar = false">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                New
            </button>
        </div>

        <div class="df-sidebar__filters">
            <div class="df-sidebar__search">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" x-model="sessionSearch" placeholder="Search sessions..." class="df-sidebar__search-input">
                <button x-show="sessionSearch" @click="sessionSearch = ''" class="df-sidebar__search-clear">&times;</button>
            </div>
            <div class="df-sidebar__status-chips">
                <button @click="sessionStatusFilter = ''" :class="sessionStatusFilter === '' ? 'df-sidebar__chip df-sidebar__chip--active' : 'df-sidebar__chip'">All</button>
                <button @click="sessionStatusFilter = 'running'" :class="sessionStatusFilter === 'running' ? 'df-sidebar__chip df-sidebar__chip--active' : 'df-sidebar__chip'">Running</button>
                <button @click="sessionStatusFilter = 'completed'" :class="sessionStatusFilter === 'completed' ? 'df-sidebar__chip df-sidebar__chip--active' : 'df-sidebar__chip'">Completed</button>
                <button @click="sessionStatusFilter = 'failed'" :class="sessionStatusFilter === 'failed' ? 'df-sidebar__chip df-sidebar__chip--active' : 'df-sidebar__chip'">Failed</button>
            </div>
        </div>

        <div class="df-sidebar__list">
            <template x-for="session in filteredSessions" :key="session.id">
                <div class="df-session-item" :class="{ 'df-session-item--active': activeSession?.id === session.id }"
                        @click="loadSession(session.id); showSidebar = false;">
                    <div class="df-session-item__top">
                        <span class="df-session-item__title" x-text="session.title || 'Discussion Session'"></span>
                        <button @click.stop="deleteSession(session.id)" class="df-session-item__delete" title="Delete">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                    <div class="df-session-item__meta">
                        <span class="df-status-badge" :class="'df-status-badge--' + session.status" x-text="session.status"></span>
                        <span class="df-session-item__date" x-text="formatDate(session.created_at)"></span>
                        <span x-show="session.model" class="df-session-item__model" x-text="session.model"></span>
                        <span x-show="session.user_name" class="df-session-item__user" x-text="'by ' + session.user_name"></span>
                    </div>
                    <p class="df-session-item__incident" x-show="session.incident" x-text="session.incident?.no + (session.incident?.severity ? ' · ' + session.incident?.severity : '') + (session.incident?.title ? ' — ' + session.incident?.title : '')"></p>
                </div>
            </template>

            <div x-show="sessions.length === 0" class="df-empty-sidebar">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" opacity="0.3">
                    <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                </svg>
                <p>No sessions yet</p>
                <span>Click "New" to start a discussion</span>
            </div>
        </div>
    </aside>

    {{-- Main content --}}
    <main class="df-main">
        {{-- Header --}}
        <header class="df-header">
            <div class="df-header__left">
                <button class="df-mobile-toggle" @click="showSidebar = !showSidebar">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="df-header__title-group">
                    <h2 class="df-header__title">
                        <span x-show="!activeSession">AI Retrospective</span>
                        <span x-show="activeSession" x-text="activeSession?.title || 'AI Retrospective'"></span>
                    </h2>
                    <p class="df-header__subtitle" x-show="activeSession?.status === 'running'">
                        <span x-show="activeSession?.incident" x-html="activeSession?.incident ? incidentLink(activeSession.incident, activeSession.incident.no + (activeSession.incident.title ? ' — ' + activeSession.incident.title : '')) + ' · ' : ''"></span>
                        Round <span x-text="activeSession?.current_round || 0"></span> of <span x-text="activeSession?.max_rounds || 2"></span> in progress
                    </p>
                    <p class="df-header__subtitle" x-show="activeSession?.status === 'pending'">
                        <span x-show="activeSession?.incident" x-html="activeSession?.incident ? incidentLink(activeSession.incident, activeSession.incident.no + (activeSession.incident.title ? ' — ' + activeSession.incident.title : '')) + ' · ' : ''"></span>
                        Preparing agents...
                    </p>
                    <p class="df-header__subtitle" x-show="activeSession?.status === 'completed' && activeSession?.incident">
                        <span x-html="activeSession?.incident ? incidentLink(activeSession.incident, activeSession.incident.no + (activeSession.incident.title ? ' — ' + activeSession.incident.title : '')) : ''"></span> · Analysis Complete
                    </p>
                    <p class="df-header__subtitle" x-show="activeSession?.status === 'failed' && activeSession?.incident">
                        <span x-html="activeSession?.incident ? incidentLink(activeSession.incident, activeSession.incident.no + (activeSession.incident.title ? ' — ' + activeSession.incident.title : '')) : ''"></span> · Failed
                    </p>
                </div>
            </div>

            <div class="df-header__actions">
                <div x-show="activeSession?.status === 'running' || activeSession?.status === 'pending'" class="df-running-indicator">
                    <span class="df-pulse-dot"></span>
                    <span x-text="activeSession?.status === 'pending' ? 'Preparing...' : 'Analyzing · Round ' + (activeSession?.current_round || 1)"></span>
                </div>

                <div x-show="activeSession?.status === 'running' && sessionProgress" class="df-progress">
                    <div class="df-progress__bar">
                        <div class="df-progress__fill" :style="'width:' + (sessionProgress?.percentage || 0) + '%'"></div>
                    </div>
                    <div class="df-progress__labels">
                        <span x-text="(sessionProgress?.completed || 0) + '/' + (sessionProgress?.total || 0) + ' agents'"></span>
                        <span x-text="'Round ' + (sessionProgress?.currentRound || 0) + '/' + (sessionProgress?.maxRounds || 0)"></span>
                    </div>
                </div>

                {{-- Primary action: View Report (completed) --}}
                <button x-show="activeSession?.status === 'completed'" @click="showReport = !showReport; scheduleMermaidRender()"
                        class="df-btn" :class="showReport ? 'df-btn--ghost' : 'df-btn--primary'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path x-show="!showReport" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        <path x-show="showReport" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                    </svg>
                    <span x-text="showReport ? 'Back to Discussion' : 'View Report'"></span>
                </button>

                {{-- Primary action: Retry All (failed) --}}
                <button x-show="activeSession?.status === 'failed'" @click="retryFailed()" class="df-btn df-btn--primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Retry Failed
                </button>

                {{-- More menu dropdown --}}
                <div class="df-menu" x-data="{ open: false }" @click.away="open = false" @keydown.escape="open = false">
                    <button @click="open = !open" class="df-btn df-btn--ghost df-btn--icon" title="More actions">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="6" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="18" r="1.5"/></svg>
                    </button>
                    <div class="df-menu__dropdown" x-show="open" x-transition:enter="df-menu-enter" x-transition:leave="df-menu-leave" @click="open = false">

                        <button x-show="canRegenerateReport()" @click="regenerateReport()" class="df-menu__item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Regenerate Report
                        </button>

                        <button x-show="canRetryReport()" @click="retryReport()" class="df-menu__item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Retry Report Only
                        </button>

                        <button x-show="activeSession?.status === 'completed' || activeSession?.status === 'failed'" @click="openReanalyzeModal()" class="df-menu__item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Re-analyze from Scratch
                        </button>

                        <a x-show="activeSession?.status === 'completed' && activeSession?.final_report_html"
                           :href="routeFor('exportPdf', activeSession?.id)"
                           class="df-menu__item" target="_blank">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                            Export PDF
                        </a>

                        <a x-show="activeSession?.status === 'completed'"
                           :href="routeFor('exportMarkdown', activeSession?.id)"
                           class="df-menu__item" target="_blank">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Export Markdown
                        </a>

                        <a x-show="activeSession"
                           :href="routeFor('exportJson', activeSession?.id)"
                           class="df-menu__item" target="_blank">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                            Export JSON
                        </a>

                        <div x-show="activeSession && activeSession.status !== 'running'" class="df-menu__divider"></div>

                        <button x-show="activeSession && activeSession.status !== 'running'" @click="deleteSession(activeSession.id)" class="df-menu__item df-menu__item--danger">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Delete Session
                        </button>
                    </div>
                </div>
            </div>
        </header>

        {{-- Scrollable content area --}}
        <div class="df-content" x-ref="contentContainer">

            {{-- ===== CREATE FORM ===== --}}
            <div x-show="showCreateForm && !activeSession" x-transition class="df-create-form">
                <div class="df-create-form__card">
                    <div class="df-create-form__header">
                        <div class="df-create-form__header-icon">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="df-create-form__title">Launch Discussion</h3>
                            <p class="df-create-form__desc">Select an incident and specialist agents for the discussion forum simulation.</p>
                        </div>
                    </div>

                    {{-- Tab navigation --}}
                    <div class="df-tabs">
                        <button class="df-tab" :class="{ 'df-tab--active': createTab === 'incident', 'df-tab--done': selectedIncidents.length > 0 && createTab !== 'incident' }" @click="createTab = 'incident'">
                            <span class="df-tab__step" :class="{ 'df-tab__step--done': selectedIncidents.length > 0 }">
                                <svg x-show="selectedIncidents.length === 0" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                <svg x-show="selectedIncidents.length > 0" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <span>Incident</span>
                        </button>
                        <button class="df-tab" :class="{ 'df-tab--active': createTab === 'agents', 'df-tab--done': selectedAgents.length > 0 && createTab !== 'agents' }" @click="createTab = 'agents'">
                            <span class="df-tab__step" :class="{ 'df-tab__step--done': selectedAgents.length > 0 }">
                                <svg x-show="selectedAgents.length === 0" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                                <svg x-show="selectedAgents.length > 0" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <span>Agents</span>
                        </button>
                        <button class="df-tab" :class="{ 'df-tab--active': createTab === 'settings' }" @click="createTab = 'settings'">
                            <span class="df-tab__step">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                            </span>
                            <span>Settings</span>
                        </button>
                    </div>

                    {{-- Tab: Incident --}}
                    <div x-show="createTab === 'incident'" class="df-tab-panel">
                        <div class="df-form-section">
                            <label class="df-label">Search Incident</label>
                            <div class="df-search-wrapper">
                                <svg class="df-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                                <input type="text" class="df-input df-input--search" x-model="incidentSearch" @input.debounce.300ms="searchIncidents()" placeholder="Search by ID, title, or summary..." />
                            </div>

                            <div x-show="incidentResults.length > 0" x-transition class="df-dropdown">
                                <template x-for="inc in incidentResults" :key="inc.id || inc.no">
                                    <button class="df-dropdown-item" @click="selectIncident(inc)">
                                        <div class="df-dropdown-item__top">
                                            <span class="df-severity-badge" :class="'df-severity-badge--' + (inc.severity || 'default')" x-text="inc.severity"></span>
                                            <span class="df-dropdown-item__id" x-text="inc.no"></span>
                                        </div>
                                        <p class="df-dropdown-item__title" x-text="inc.title"></p>
                                    </button>
                                </template>
                            </div>

                            <div x-show="selectedIncidents.length > 0" x-transition class="df-selected-incidents">
                                <template x-for="(inc, idx) in selectedIncidents" :key="inc.id">
                                    <div class="df-selected-inc">
                                        <div class="df-selected-inc__info">
                                            <span class="df-selected-inc__id" x-text="inc.no"></span>
                                            <span class="df-selected-inc__sep">&mdash;</span>
                                            <span class="df-selected-inc__title" x-text="inc.title"></span>
                                        </div>
                                        <button @click="removeIncident(idx)" class="df-selected-inc__remove">&times;</button>
                                    </div>
                                </template>
                                <div x-show="tokenEstimate !== null" x-transition class="df-token-warning"
                                     :class="{ 'df-token-warning--ok': tokenEstimate?.percentage <= 50, 'df-token-warning--warn': tokenEstimate?.percentage > 50 && tokenEstimate?.percentage <= 75, 'df-token-warning--danger': tokenEstimate?.percentage > 75 }">
                                    <svg class="df-token-warning__icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                                    <div class="df-token-warning__content">
                                        <span x-text="tokenEstimate ? ('~' + tokenEstimate.estimated_tokens.toLocaleString() + ' / ' + tokenEstimate.input_limit.toLocaleString() + ' tokens (' + tokenEstimate.percentage + '%)' + (tokenEstimate.will_compress ? ' — will auto-compress' : '')) : ''"></span>
                                        <div class="df-token-bar">
                                            <div class="df-token-bar__fill" :style="'width:' + Math.min(tokenEstimate?.percentage || 0, 100) + '%'"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="df-tab-actions">
                            <button @click="createTab = 'agents'" :disabled="selectedIncidents.length === 0" class="df-btn df-btn--primary df-btn--full"
                                    :class="{ 'df-btn--disabled': selectedIncidents.length === 0 }">
                                Continue to Agents
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>

                    {{-- Tab: Agents --}}
                    <div x-show="createTab === 'agents'" class="df-tab-panel">
                        <div class="df-form-section">
                            <label class="df-label">Specialist Agents <span class="df-label__count" x-text="selectedAgents.length ? selectedAgents.length + ' selected' : ''"></span></label>
                            <div class="df-agent-roster">
                                <template x-for="agent in availableAgents" :key="agent.role_key">
                                    <button @click="toggleAgent(agent.role_key)" class="df-agent-card"
                                            :class="{ 'df-agent-card--selected': selectedAgents.includes(agent.role_key) }"
                                            :style="'--agent-color:' + getAgentColor(agent.color)">
                                        <div class="df-agent-card__glow"></div>
                                        <div class="df-agent-card__inner">
                                            <div class="df-agent-card__avatar" :style="'--agent-color:' + getAgentColor(agent.color)">
                                                <span x-text="getAgentInitial(agent.display_name)"></span>
                                            </div>
                                            <div class="df-agent-card__body">
                                                <div class="df-agent-card__header">
                                                    <span class="df-agent-card__name" x-text="agent.display_name"></span>
                                                    <div class="df-agent-card__toggle"
                                                         :class="selectedAgents.includes(agent.role_key) ? 'df-agent-card__toggle--on' : ''">
                                                        <svg x-show="selectedAgents.includes(agent.role_key)" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><path d="M5 13l4 4L19 7"/></svg>
                                                    </div>
                                                </div>
                                                <p x-show="agent.description" class="df-agent-card__desc" x-text="agent.description"></p>
                                                <div x-show="agent.skills && agent.skills.length > 0" class="df-agent-card__skills">
                                                    <template x-for="skill in (agent.skills || []).slice(0, 4)" :key="skill">
                                                        <span class="df-agent-card__skill" x-text="skill"></span>
                                                    </template>
                                                    <span x-show="(agent.skills || []).length > 4" class="df-agent-card__more" x-text="'+' + ((agent.skills || []).length - 4) + ' more'"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </button>
                                </template>
                            </div>
                            <div x-show="availableAgents.length === 0" class="df-hint">Loading agents...</div>
                        </div>
                        <div class="df-tab-actions">
                            <button @click="createTab = 'incident'" class="df-btn df-btn--ghost">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 19l-7-7 7-7"/></svg>
                                Back
                            </button>
                            <button @click="createTab = 'settings'" :disabled="selectedAgents.length === 0" class="df-btn df-btn--primary"
                                    :class="{ 'df-btn--disabled': selectedAgents.length === 0 }">
                                Continue to Settings
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>

                    {{-- Tab: Settings --}}
                    <div x-show="createTab === 'settings'" class="df-tab-panel">
                        <div class="df-form-row">
                            <div class="df-form-field">
                                <label class="df-label">Discussion Rounds</label>
                                <select x-model.number="config.maxRounds" class="df-select">
                                    <option value="1">1 round (quick)</option>
                                    <option value="2">2 rounds (standard)</option>
                                    <option value="3">3 rounds (deep)</option>
                                </select>
                            </div>
                            <div class="df-form-field">
                                <label class="df-label">Agent Model</label>
                                <select x-model="config.model" class="df-select">
                                    <option value="">Default</option>
                                    <template x-for="(name, id) in models" :key="id">
                                        <option :value="id" x-text="name"></option>
                                    </template>
                                </select>
                            </div>
                        </div>

                        <label class="df-checkbox-row">
                            <input type="checkbox" x-model="config.enableWebSearch" class="df-checkbox" />
                            <span>Enable web search for agents</span>
                        </label>
                        <label class="df-checkbox-row">
                            <input type="checkbox" x-model="config.deepAnalysis" class="df-checkbox" />
                            <span>Deep analysis (full incident data — uses more tokens)</span>
                        </label>

                        <div class="df-form-section">
                            <label class="df-label">Additional Instructions <span class="df-label__hint">(optional)</span></label>
                            <textarea x-model="config.userInstructions" class="df-textarea" rows="3"
                                placeholder="Add extra context, focus areas, or specific questions you want the agents to address..."></textarea>
                        </div>

                        {{-- Templates --}}
                        <div class="df-form-section">
                            <label class="df-label">Templates <span class="df-label__hint">(save & load agent presets)</span></label>
                            <div class="df-template-save">
                                <input type="text" x-model="templateName" class="df-input" placeholder="Template name..." />
                                <button @click="saveTemplate()" :disabled="!templateName.trim() || selectedAgents.length === 0"
                                        class="df-btn df-btn--primary df-btn--sm">Save</button>
                            </div>
                            <template x-for="tpl in templates" :key="tpl.id">
                                <div class="df-template-item">
                                    <button @click="applyTemplate(tpl)" class="df-template-item__name" x-text="tpl.name"></button>
                                    <span class="df-template-item__agents" x-text="(tpl.selected_agents?.length || 0) + ' agents'"></span>
                                    <button @click="deleteTemplate(tpl.id)" class="df-template-item__delete" title="Delete template">&times;</button>
                                </div>
                            </template>
                        </div>

                        <div class="df-tab-actions">
                            <button @click="createTab = 'agents'" class="df-btn df-btn--ghost">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 19l-7-7 7-7"/></svg>
                                Back
                            </button>
                            <button @click="createSession()" :disabled="selectedIncidents.length === 0 || selectedAgents.length === 0 || creating"
                                    class="df-btn df-btn--launch df-btn--launch-inline"
                                    :class="{ 'df-btn--disabled': selectedIncidents.length === 0 || selectedAgents.length === 0 || creating }">
                                <svg x-show="!creating" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                                <svg x-show="creating" width="16" height="16" class="df-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4m-3.93 7.07l-2.83-2.83M6.76 6.76L3.93 3.93"/>
                                </svg>
                                <span x-text="creating ? 'Launching...' : 'Launch Discussion'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ===== USER INSTRUCTIONS BANNER ===== --}}
            <div x-show="activeSession && activeSession.user_instructions && !showReport" x-transition class="df-instructions-banner">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span x-text="activeSession?.user_instructions"></span>
            </div>

            {{-- ===== PRE-ANALYSIS / STARTING STATE ===== --}}
            <div x-show="activeSession && activeSession.status === 'running' && getSortedRounds().length === 0 && !activeSession.pre_analysis" x-transition class="df-starting-state">
                <div class="df-starting-state__icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                </div>
                <h3 class="df-starting-state__title">Analyzing Incident</h3>
                <p class="df-starting-state__desc">Pre-analysis is running to identify key concerns and guide specialist agents...</p>
                <div class="df-starting-state__loader">
                    <div class="df-loader df-loader--active"></div>
                    <span>This may take a moment</span>
                </div>
            </div>

            <div x-show="activeSession && activeSession.pre_analysis" x-data="{ preAnalysisOpen: true }" x-transition class="df-pre-analysis">
                <div class="df-pre-analysis__header" @click="preAnalysisOpen = !preAnalysisOpen" style="cursor:pointer;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                    <span class="df-pre-analysis__title">Pre-Analysis Insights</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left:auto;transition:transform .2s" :style="preAnalysisOpen ? 'transform:rotate(180deg)' : ''"><path d="m6 9 6 6 6-6"/></svg>
                </div>
                <div x-show="preAnalysisOpen" x-transition class="df-pre-analysis__body">
                    <div x-show="activeSession.pre_analysis?.severity_assessment" class="df-pre-analysis__severity">
                        <strong x-text="'Severity: ' + (activeSession.pre_analysis?.severity_assessment?.level || '')"></strong>
                        <span x-text="activeSession.pre_analysis?.severity_assessment?.reasoning || ''"></span>
                    </div>
                    <div x-show="activeSession.pre_analysis?.key_concerns?.length" class="df-pre-analysis__section">
                        <h4>Key Concerns</h4>
                        <ul>
                            <template x-for="concern in (activeSession.pre_analysis?.key_concerns || [])"><li x-text="concern"></li></template>
                        </ul>
                    </div>
                    <div x-show="activeSession.pre_analysis?.hypotheses?.length" class="df-pre-analysis__section">
                        <h4>Root Cause Hypotheses</h4>
                        <ul>
                            <template x-for="hyp in (activeSession.pre_analysis?.hypotheses || [])">
                                <li><span class="df-pre-analysis__likelihood" x-text="'[' + hyp.likelihood + ']'"></span> <span x-text="hyp.description"></span></li>
                            </template>
                        </ul>
                    </div>
                    <div x-show="activeSession.pre_analysis?.data_gaps?.length" class="df-pre-analysis__section">
                        <h4>Data Gaps</h4>
                        <ul>
                            <template x-for="gap in (activeSession.pre_analysis?.data_gaps || [])"><li x-text="gap"></li></template>
                        </ul>
                    </div>
                    <div x-show="activeSession.pre_analysis?.reasoning" class="df-pre-analysis__section">
                        <h4>Summary</h4>
                        <p x-text="activeSession.pre_analysis?.reasoning"></p>
                    </div>
                </div>
            </div>

            {{-- ===== DISCUSSION ROUNDS ===== --}}
            <div x-show="activeSession && !showReport">
                <template x-for="round in getSortedRounds()" :key="round">
                    <section class="df-round">
                        <div class="df-round__header">
                            <div class="df-round__badge" x-text="round"></div>
                            <h3 class="df-round__title" x-text="round === 1 ? 'Initial Analysis' : 'Discussion Round ' + round"></h3>
                            <span class="df-round__stats" x-text="getRoundStats(round).completed + '/' + getRoundStats(round).total"></span>
                            <div class="df-round__actions">
                                <button @click="expandAll(round)" class="df-round__action-btn" title="Expand all">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m7 13 5 5 5-5"/><path d="m7 6 5 5 5-5"/></svg>
                                </button>
                                <button @click="collapseAll(round)" class="df-round__action-btn" title="Collapse all">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m7 11 5-5 5 5"/><path d="m7 18 5-5 5 5"/></svg>
                                </button>
                            </div>
                            <div class="df-round__line"></div>
                        </div>

                        <div class="df-round__messages">
                            <template x-for="msg in getRoundMessages(round)" :key="msg.id">
                                <div class="df-msg" :class="'df-msg--' + msg.status">
                                    <div class="df-msg__accent" :style="'background:' + getAgentColor(msg.agent_color || 'gray')"></div>
                                    <div class="df-msg__body">
                                        {{-- Header: Agent identity --}}
                                        <div class="df-msg__header">
                                            <div class="df-msg__author">
                                                <div class="df-msg__avatar" :style="'background:' + getAgentColor(msg.agent_color || 'gray', 0.15) + '; color:' + getAgentColor(msg.agent_color || 'gray')"
                                                     x-text="getAgentInitial(msg.agent_name)"></div>
                                                <div class="df-msg__identity">
                                                    <span class="df-msg__name" x-text="msg.agent_name"></span>
                                                    <span class="df-msg__role" x-text="msg.agent_role"></span>
                                                </div>
                                            </div>
                                            <button x-show="msg.content" @click="msg._expanded = !msg._expanded; scheduleMermaidRender()" class="df-msg__toggle">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                     :style="msg._expanded ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s">
                                                    <path d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </button>
                                        </div>

                                        {{-- Meta strip: metrics --}}
                                        <div class="df-msg__meta" x-show="msg.status === 'completed'">
                                            <span class="df-msg__metric" :class="getTimeColor(msg.response_time_ms)" x-show="msg.response_time_ms">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                                                <span x-text="(msg.response_time_ms / 1000).toFixed(1) + 's'"></span>
                                            </span>
                                            <span class="df-msg__metric" :class="getTokenColor(msg.total_tokens)" x-show="msg.total_tokens">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7v10a2 2 0 002 2h12a2 2 0 002-2V7M4 7h16"/></svg>
                                                <span x-text="msg.total_tokens ? msg.total_tokens.toLocaleString() + ' tokens' : ''"></span>
                                            </span>
                                            <span class="df-msg__metric" :class="getTokenColor(msg.reasoning_tokens)" x-show="msg.reasoning_tokens">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                                <span x-text="msg.reasoning_tokens?.toLocaleString() + ' reasoning'"></span>
                                            </span>
                                            <span class="df-msg__metric df-msg__metric--model" x-show="msg.model">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6m-6 4h6"/></svg>
                                                <span x-text="msg.model"></span>
                                            </span>
                                            <span class="df-msg__status-dot" :class="'df-msg__status-dot--' + msg.status">
                                                <span class="df-msg__status-dot__ring"></span>
                                                <span x-text="msg.status"></span>
                                            </span>
                                        </div>

                                        {{-- Tool Usage --}}
                                        <div x-show="msg.status === 'completed' && (msg.tool_calls?.length > 0 || msg.web_search_context)" class="df-msg__tools">
                                            <button @click="msg._showTools = !msg._showTools" class="df-msg__tools-toggle">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                                                <span x-text="(msg.tool_calls?.length || 0) + (msg.web_search_context ? 1 : 0) + ' tool(s) used'"></span>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                     style="transition:transform 0.2s" :style="msg._showTools ? 'transform:rotate(180deg)' : ''">
                                                    <path d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </button>
                                            <div x-show="msg._showTools" x-transition class="df-msg__tools-content">
                                                <template x-for="(tc, i) in msg.tool_calls || []" :key="'tc-'+i">
                                                    <div class="df-msg__tool-item">
                                                        <span class="df-msg__tool-name" x-text="toolLabel(tc.name)"></span>
                                                        <template x-if="tc.arguments">
                                                            <span class="df-msg__tool-args" x-text="JSON.parse(tc.arguments || '{}')?.query || JSON.parse(tc.arguments || '{}')?.incident_no || JSON.parse(tc.arguments || '{}')?.incident_id || ''"></span>
                                                        </template>
                                                    </div>
                                                </template>
                                                <div x-show="msg.web_search_context" class="df-msg__tool-item">
                                                    <span class="df-msg__tool-name">Web Search Results</span>
                                                    <span class="df-msg__tool-args" x-text="msg.web_search_context ? (msg.web_search_context.substring(0, 200) + (msg.web_search_context.length > 200 ? '...' : '')) : ''"></span>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Thinking/Reasoning Process --}}
                                        <div x-show="msg.status === 'completed' && msg.reasoning_content" class="df-msg__thinking">
                                            <button @click="msg._showThinking = !msg._showThinking" class="df-msg__thinking-toggle">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                                <span>Thinking Process</span>
                                                <span class="df-msg__thinking-tokens" x-show="msg.reasoning_tokens"
                                                      x-text="msg.reasoning_tokens?.toLocaleString() + ' reasoning tokens'"></span>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                     style="transition:transform 0.2s" :style="msg._showThinking ? 'transform:rotate(180deg)' : ''">
                                                    <path d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </button>
                                            <div x-show="msg._showThinking" x-transition class="df-msg__thinking-content"
                                                 x-html="renderMarkdown(msg.reasoning_content || '')">
                                            </div>
                                        </div>

                                        {{-- Pending state --}}
                                        <div x-show="msg.status === 'pending'" class="df-msg__state df-msg__state--pending">
                                            <div class="df-loader"></div>
                                            <span>Waiting for response...</span>
                                        </div>

                                        {{-- Running state --}}
                                        <div x-show="msg.status === 'running' && !msg.content" class="df-msg__state df-msg__state--running">
                                            <div class="df-loader df-loader--active"></div>
                                            <span>Analyzing incident data...</span>
                                        </div>

                                        {{-- Failed state --}}
                                        <div x-show="msg.status === 'failed'" class="df-msg__state df-msg__state--failed">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                                            <span x-text="msg.error_message || 'Agent processing failed'"></span>
                                            <button @click="retryAgent(msg.id)" class="df-retry-btn" title="Retry this agent">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                                                Retry
                                            </button>
                                        </div>

                                        {{-- Streaming content (while running) --}}
                                        <div x-show="msg.status === 'running' && msg.content"
                                             x-html="renderAgentContent(msg)"
                                             class="df-markdown df-msg__content">
                                        </div>

                                        {{-- Completed content --}}
                                        <div x-show="msg.status === 'completed' && msg.content"
                                             x-html="renderAgentContent(msg)"
                                             class="df-markdown df-msg__content"
                                             :class="{ 'df-msg__content--collapsed': !msg._expanded }">
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </section>
                </template>
            </div>

            {{-- ===== FINAL REPORT ===== --}}
            <div x-show="activeSession && showReport && (activeSession?.status === 'completed' || activeSession?._streamingReport)" x-transition class="df-report">
                <div class="df-report__banner">
                    <div class="df-report__banner-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="df-report__title">Analysis Report</h2>
                        <p class="df-report__subtitle" x-text="activeSession?.title"></p>
                        <div class="df-report__meta">
                            <span x-show="activeSession?.incident" class="df-report__meta-chip">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                <span x-html="incidentLink(activeSession?.incident, activeSession?.incident?.no + ' · ' + (activeSession?.incident?.severity || '') + (activeSession?.incident?.title ? ' — ' + activeSession?.incident?.title : ''))"></span>
                            </span>
                            <span class="df-report__meta-chip">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                                <span x-text="formatDate(activeSession?.created_at)"></span>
                            </span>
                            <span x-show="activeSession?.model" class="df-report__meta-chip">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6m-6 4h6m-6 4h4"/><path d="M9 1v3M15 1v3"/></svg>
                                <span x-text="activeSession?.model || 'Default'"></span>
                            </span>
                            <span class="df-report__meta-chip">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                                <span x-text="(activeSession?.selected_agents?.length || 0) + ' agents'"></span>
                            </span>
                            <span class="df-report__meta-chip" :class="activeSession?.deep_analysis === false ? 'df-report__meta-chip--muted' : ''">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <span x-text="activeSession?.deep_analysis === false ? 'Quick Discussion' : 'Deep Analysis'"></span>
                            </span>
                        </div>
                    </div>
                </div>

                <div x-show="activeSession?._streamingReport && !activeSession?.final_report_html" x-html="activeSession?._reportStreamingHtml || ''"
                     class="df-markdown df-report__body df-report__body--streaming">
                </div>

                <div x-show="activeSession?.final_report_html" x-html="renderMarkdown(activeSession?.final_report_html || '')"
                     class="df-markdown df-report__body">
                </div>

                <div x-show="!activeSession?.final_report_html && !activeSession?._streamingReport" class="df-report__loading">
                    <div class="df-loader df-loader--active"></div>
                    <p>Generating report...</p>
                </div>

                <div x-show="activeSession?.tokens_used" class="df-report__footer">
                    <div class="df-report__footer-item">
                        <span class="df-report__footer-label">Tokens</span>
                        <strong x-text="activeSession?.tokens_used?.toLocaleString()"></strong>
                    </div>
                    <div class="df-report__footer-item" x-show="activeSession?.model">
                        <span class="df-report__footer-label">Model</span>
                        <strong x-text="activeSession?.model || 'Default'"></strong>
                    </div>
                    <div class="df-report__footer-item">
                        <span class="df-report__footer-label">Rounds</span>
                        <strong x-text="activeSession?.max_rounds || 2"></strong>
                    </div>
                    <div class="df-report__footer-item">
                        <span class="df-report__footer-label">Analysis</span>
                        <strong x-text="activeSession?.deep_analysis === false ? 'Quick' : 'Deep'"></strong>
                    </div>
                </div>
            </div>

        </div>
    </main>

    {{-- Re-analyze Modal --}}
    <div x-show="showReanalyzeModal" x-transition.opacity class="df-modal-backdrop" @click.self="showReanalyzeModal = false">
        <div x-show="showReanalyzeModal" x-transition class="df-modal" @click.stop style="max-width: 560px;">
            <div class="df-modal__header">
                <div class="df-modal__header-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </div>
                <div>
                    <h3 class="df-modal__title">Re-analyze Discussion</h3>
                    <p class="df-modal__desc">Re-run with fresh incident data. Previous responses will be cleared.</p>
                </div>
            </div>
            <div class="df-modal__body" style="display: flex; flex-direction: column; gap: 16px;">
                {{-- Model selection --}}
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label class="df-label">Agent Model</label>
                        <select x-model="reanalyzeModel" class="df-select" style="width: 100%;">
                            <option value="">Use current</option>
                            <template x-for="(label, key) in models" :key="key">
                                <option :value="key" x-text="label"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="df-label">Moderator Model</label>
                        <select x-model="reanalyzeModeratorModel" class="df-select" style="width: 100%;">
                            <option value="">Use current</option>
                            <template x-for="(label, key) in models" :key="key">
                                <option :value="key" x-text="label"></option>
                            </template>
                        </select>
                    </div>
                </div>

                {{-- Agent selection --}}
                <div style="margin-bottom: 12px;">
                    <label class="df-checkbox-row" style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                        <input type="checkbox" x-model="reanalyzeDeepAnalysis" class="df-checkbox" />
                        <span style="font-size:13px;font-weight:500;color:var(--df-text);">Deep analysis (full incident data)</span>
                    </label>
                </div>
                <div>
                    <label class="df-label">Specialist Agents <span class="df-label__count" x-text="reanalyzeAgents.length ? reanalyzeAgents.length + ' selected' : ''"></span></label>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;">
                        <template x-for="agent in availableAgents" :key="agent.role_key">
                            <button @click="toggleReanalyzeAgent(agent.role_key)"
                                    class="df-agent-chip"
                                    :class="{ 'df-agent-chip--selected': reanalyzeAgents.includes(agent.role_key) }"
                                    :style="'--agent-color:' + getAgentColor(agent.color)">
                                <span x-text="agent.display_name"></span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Instructions --}}
                <div>
                    <label class="df-label">Additional Instructions <span class="df-label__hint">(optional)</span></label>
                    <textarea x-model="reanalyzeInstructions" class="df-textarea" rows="3"
                        placeholder="Add extra context, focus areas, or specific questions for the agents..."></textarea>
                </div>
            </div>
            <div class="df-modal__footer">
                <button @click="showReanalyzeModal = false" class="df-btn df-btn--ghost">Cancel</button>
                <button @click="submitReanalyze()" :disabled="reanalyzing || reanalyzeAgents.length === 0" class="df-btn df-btn--primary" :class="{ 'df-btn--disabled': reanalyzing || reanalyzeAgents.length === 0 }">
                    <svg x-show="!reanalyzing" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <svg x-show="reanalyzing" width="14" height="14" class="df-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4m-3.93 7.07l-2.83-2.83M6.76 6.76L3.93 3.93"/></svg>
                    <span x-text="reanalyzing ? 'Re-analyzing...' : 'Re-analyze'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

@push('styles')
<link rel="stylesheet" href="{{ asset('css/war-room.css') }}">
@endpush


</x-filament-panels::page>
