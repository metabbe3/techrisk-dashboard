<x-filament-panels::page>
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('warRoom', () => {
        const routes = {
            agents: '{{ route("war-room.agents") }}',
            sessions: '{{ route("war-room.sessions") }}',
            create: '{{ route("war-room.create") }}',
            incidentSearch: '{{ route("war-room.incident-search") }}',
            estimateTokens: '{{ route("war-room.estimate-tokens") }}',
        };

        const colorMap = {
            blue: '#3b82f6', indigo: '#6366f1', purple: '#8b5cf6', green: '#22c55e',
            teal: '#14b8a6', cyan: '#06b6d4', red: '#ef4444', orange: '#f97316',
            amber: '#f59e0b', pink: '#ec4899', emerald: '#10b981', gray: '#6b7280',
            fuchsia: '#d946ef', rose: '#f43f5e', sky: '#0ea5e9',
            violet: '#8b5cf6', yellow: '#eab308', lime: '#84cc16',
        };

        function hexToRgba(hex, alpha) {
            const r = parseInt(hex.slice(1, 3), 16);
            const g = parseInt(hex.slice(3, 5), 16);
            const b = parseInt(hex.slice(5, 7), 16);
            return `rgba(${r}, ${g}, ${b}, ${alpha})`;
        }

        return {
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
            defaultModel: '{!! addslashes($defaultModel) !!}',

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

            get filteredSessions() {
                let list = this.sessions;
                if (this.sessionStatusFilter) {
                    list = list.filter(s => s.status === this.sessionStatusFilter);
                }
                if (this.sessionSearch.trim()) {
                    const q = this.sessionSearch.toLowerCase().trim();
                    list = list.filter(s =>
                        (s.title || '').toLowerCase().includes(q) ||
                        (s.incident?.no || '').toLowerCase().includes(q) ||
                        (s.incident?.title || '').toLowerCase().includes(q)
                    );
                }
                return list;
            },

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
                };
                url = map[name] || '';
                url = url.replace('__ID__', ids[0] || '');
                if (ids[1]) url = url.replace('__MSG__', ids[1]);
                return url;
            },

            toolLabel(name) {
                const map = {
                    search_incidents: 'Searched Incidents',
                    get_incident_details: 'Fetched Incident Details',
                    find_similar_incidents: 'Found Similar Incidents',
                    get_action_items: 'Retrieved Action Items',
                    web_search: 'Web Search',
                    get_stats: 'Retrieved Statistics',
                };
                return map[name] || name;
            },

            expandAll(round) {
                (this.activeSession?.messages?.[round] || []).forEach(m => m._expanded = true);
                this.scheduleMermaidRender();
            },
            collapseAll(round) {
                (this.activeSession?.messages?.[round] || []).forEach(m => m._expanded = false);
            },

            getHeaders(withContentType = false) {
                const headers = {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                };
                if (withContentType) headers['Content-Type'] = 'application/json';
                return headers;
            },

            getAgentColor(name, alpha = 1) {
                const hex = colorMap[name] || colorMap.gray;
                return alpha === 1 ? hex : hexToRgba(hex, alpha);
            },

            getTimeColor(ms) {
                if (!ms) return '';
                const s = ms / 1000;
                if (s <= 5) return 'df-metric--ok';
                if (s <= 15) return 'df-metric--warn';
                return 'df-metric--danger';
            },

            getTokenColor(tokens) {
                if (!tokens) return '';
                if (tokens <= 1000) return 'df-metric--ok';
                if (tokens <= 3000) return 'df-metric--warn';
                return 'df-metric--danger';
            },

            _agentCache: null,

            buildAgentCache() {
                if (this._agentCache) return this._agentCache;
                const lookup = {};
                const regexes = [];
                for (const agent of this.availableAgents) {
                    lookup[agent.display_name] = { color: agent.color, role: agent.role_key, name: agent.display_name };
                }
                const names = Object.keys(lookup).sort((a, b) => b.length - a.length);
                for (const name of names) {
                    regexes.push({ name, agent: lookup[name], regex: new RegExp(`(?<![\\w\\/])${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w])`, 'g') });
                }
                this._agentCache = { lookup, regexes };
                return this._agentCache;
            },

            processAgentReferences(text) {
                if (!text) return '';
                const parts = text.split(/(```[\s\S]*?```|`[^`]+`)/g);
                const { regexes } = this.buildAgentCache();
                if (regexes.length === 0) return text;

                return parts.map((part, i) => {
                    if (i % 2 === 1) return part;
                    let processed = part;
                    for (const { name, agent, regex } of regexes) {
                        const color = this.getAgentColor(agent.color);
                        processed = processed.replace(regex,
                            `<span class="df-agent-ref" style="--ref-color:${color}">` +
                            `<span class="df-agent-ref__dot" style="background:${color}"></span>` +
                            `${name}</span>`
                        );
                    }
                    return processed;
                }).join('');
            },

            renderAgentContent(msg) {
                const text = msg._expanded ? msg.content : (msg.content || '').substring(0, 300) + ((msg.content || '').length > 300 ? '...' : '');
                if (!text) return '';
                let processed = this.processAgentReferences(text);
                processed = this.processIncidentLinks(processed);
                if (typeof marked !== 'undefined') {
                    try { return marked.parse(processed, { breaks: true, gfm: true }); }
                    catch { return processed.replace(/\n/g, '<br>'); }
                }
                return processed.replace(/\n/g, '<br>');
            },

            getAgentInitial(name) {
                if (!name) return '?';
                const words = name.trim().split(/\s+/);
                if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
                return name.slice(0, 2).toUpperCase();
            },

            incidentLink(incident, label = '') {
                if (!incident?.id) return label || incident?.no || '';
                const text = label || incident.no;
                return '<a href="/admin/incidents/' + incident.id + '" target="_blank" class="df-incident-link">' + text + '</a>';
            },

            _incidentIdMap: null,
            buildIncidentIdMap() {
                const sessionId = this.activeSession?.id;
                if (this._incidentIdMap && this._incidentIdMap._sessionId === sessionId) return this._incidentIdMap;
                const map = { _sessionId: sessionId };
                if (this.activeSession?.incident?.id && this.activeSession?.incident?.no) {
                    map[this.activeSession.incident.no] = this.activeSession.incident.id;
                }
                if (this.activeSession?.related_incidents) {
                    for (const inc of this.activeSession.related_incidents) {
                        if (inc.id && inc.no) map[inc.no] = inc.id;
                    }
                }
                this._incidentIdMap = map;
                return map;
            },

            processIncidentLinks(text) {
                if (!text) return '';
                const parts = text.split(/(```[\s\S]*?```|`[^`]+`)/g);
                const map = this.buildIncidentIdMap();
                const regex = /\d{8}_(?:IN|IS)_\d{4}/g;
                return parts.map((part, i) => {
                    if (i % 2 === 1) return part;
                    return part.replace(regex, (match) => {
                        const id = map[match];
                        if (!id) return match;
                        return '<a href="/admin/incidents/' + id + '" target="_blank" class="df-incident-link">' + match + '</a>';
                    });
                }).join('');
            },

            async init() {
                if (typeof mermaid !== 'undefined') {
                    mermaid.initialize({
                        startOnLoad: false,
                        theme: document.documentElement.classList.contains('dark') ? 'dark' : 'default',
                    });
                }
                await this.loadAgents();
                await this.loadSessions();
                const params = new URLSearchParams(window.location.search);
                const sessionId = params.get('session');
                if (sessionId) {
                    await this.loadSession(sessionId);
                }
            },

            async loadAgents() {
                try {
                    const res = await fetch(routes.agents, { headers: this.getHeaders() });
                    if (!res.ok) { console.error('Load agents failed:', res.status, await res.text()); return; }
                    this.availableAgents = await res.json();
                    this._agentCache = null;
                } catch (e) { console.error('Failed to load agents:', e); }
            },

            async loadSessions() {
                try {
                    const res = await fetch(routes.sessions, { headers: this.getHeaders() });
                    if (!res.ok) { console.error('Load sessions failed:', res.status, await res.text()); return; }
                    const data = await res.json();
                    this.sessions = data.data || data;
                } catch (e) { console.error('Failed to load sessions:', e); }
            },

            async searchIncidents() {
                if (this.incidentSearch.length < 2) { this.incidentResults = []; return; }
                try {
                    const res = await fetch(routes.incidentSearch + '?q=' + encodeURIComponent(this.incidentSearch), { headers: this.getHeaders() });
                    if (!res.ok) { console.error('Search incidents failed:', res.status, await res.text()); return; }
                    const data = await res.json();
                    this.incidentResults = data.incidents || [];
                } catch (e) { console.error('Failed to search incidents:', e); }
            },

            selectIncident(inc) {
                if (this.selectedIncidents.find(i => i.id === inc.id)) return;
                this.selectedIncidents.push(inc);
                this.incidentResults = [];
                this.incidentSearch = '';
                this.fetchTokenEstimate();
            },

            removeIncident(idx) {
                this.selectedIncidents.splice(idx, 1);
                this.fetchTokenEstimate();
            },

            toggleAgent(role) {
                const idx = this.selectedAgents.indexOf(role);
                if (idx === -1) { this.selectedAgents.push(role); }
                else { this.selectedAgents.splice(idx, 1); }
            },

            async fetchTokenEstimate() {
                if (this.selectedIncidents.length === 0) { this.tokenEstimate = null; return; }
                clearTimeout(this.tokenDebounce);
                this.tokenDebounce = setTimeout(async () => {
                    try {
                        const params = new URLSearchParams({
                            incident_ids: this.selectedIncidents.map(i => i.id).join(','),
                            model: this.config.model || '',
                            deep_analysis: this.config.deepAnalysis,
                        });
                        const res = await fetch(routes.estimateTokens + '?' + params, { headers: this.getHeaders() });
                        if (res.ok) { this.tokenEstimate = await res.json(); }
                    } catch (e) { /* silent */ }
                }, 300);
            },

            async createSession() {
                if (this.selectedIncidents.length === 0 || this.selectedAgents.length === 0 || this.creating) return;
                this.creating = true;
                try {
                    const res = await fetch(routes.create, {
                        method: 'POST',
                        headers: this.getHeaders(true),
                        body: JSON.stringify({
                            incident_ids: this.selectedIncidents.map(i => i.id),
                            selected_agents: this.selectedAgents,
                            max_rounds: this.config.maxRounds,
                            model: this.config.model || null,
                            moderator_model: this.config.moderatorModel || null,
                            enable_web_search: this.config.enableWebSearch,
                            deep_analysis: this.config.deepAnalysis,
                            user_instructions: this.config.userInstructions || null,
                        })
                    });
                    const data = await res.json();
                    if (res.ok) {
                        this.showCreateForm = false;
                        await this.loadSession(data.id);
                        await this.loadSessions();
                    } else if (res.status === 409 && data.existing_session) {
                        const existing = data.existing_session;
                        const action = confirm(
                            'This incident already has a discussion session (' + existing.status + ', created ' + new Date(existing.created_at).toLocaleDateString() + ').\n\nClick OK to view the existing session, or Cancel to go back.'
                        );
                        if (action) {
                            this.showCreateForm = false;
                            await this.loadSession(existing.id);
                        }
                    } else {
                        console.error('Create session error:', res.status, data);
                        alert('Failed to create session: ' + (data.message || JSON.stringify(data)));
                    }
                } catch (e) {
                    console.error('Create session exception:', e);
                    alert('Error creating session: ' + e.message);
                } finally {
                    this.creating = false;
                }
            },

            async loadSession(id) {
                try {
                    const res = await fetch(this.routeFor('show', id), { headers: this.getHeaders() });
                    if (!res.ok) { console.error('Load session failed:', res.status, await res.text()); return; }
                    this.activeSession = await res.json();
                    this.showCreateForm = false;
                    this.showReport = false;

                    if (this.activeSession.messages) {
                        Object.values(this.activeSession.messages).flat().forEach(m => { m._expanded = false; m._showThinking = false; m._showTools = false; });
                    }

                    this.startPolling();
                    this.scheduleMermaidRender();
                } catch (e) { console.error('Failed to load session:', e); }
            },

            startPolling() {
                this.stopPolling();
                if (!this.activeSession) return;
                if (this.activeSession.status !== 'running' && this.activeSession.status !== 'pending') return;

                // WebSocket: real-time push
                if (window.Echo) {
                    window.Echo.private('war-room.' + this.activeSession.id)
                        .listen('.message.updated', (e) => this.onMessageUpdated(e))
                        .listen('.round.completed', (e) => this.onRoundCompleted(e))
                        .listen('.session.completed', (e) => this.onSessionCompleted(e));
                }

                // Fallback: slow poll every 15s in case WebSocket drops
                this.pollInterval = setInterval(() => this.poll(), 15000);
            },

            stopPolling() {
                if (this.pollInterval) {
                    clearInterval(this.pollInterval);
                    this.pollInterval = null;
                }
                if (window.Echo && this.activeSession) {
                    window.Echo.leave('war-room.' + this.activeSession.id);
                }
            },

            async onMessageUpdated(e) {
                if (!this.activeSession) return;
                const prevStatus = this.activeSession.status;
                await this.loadSession(this.activeSession.id);
                // If session transitioned from pending → running, start fresh polling
                if (prevStatus === 'pending' && this.activeSession.status === 'running') {
                    this.startPolling();
                }
            },

            async onRoundCompleted(e) {
                if (!this.activeSession) return;
                await this.loadSession(this.activeSession.id);
            },

            async onSessionCompleted(e) {
                this.stopPolling();
                await this.loadSession(this.activeSession.id);
                await this.loadSessions();
            },

            async poll() {
                if (!this.activeSession || (this.activeSession.status !== 'running' && this.activeSession.status !== 'pending')) {
                    this.stopPolling();
                    return;
                }
                try {
                    const res = await fetch(this.routeFor('poll', this.activeSession.id), { headers: this.getHeaders() });
                    const data = await res.json();
                    this.activeSession.status = data.status;
                    this.activeSession.current_round = data.current_round;
                    this.activeSession.error_message = data.error_message;

                    // If status changed from pending → running, reload to get new messages
                    if (data.status === 'running' && (!this.activeSession.messages || Object.keys(this.activeSession.messages || {}).length === 0)) {
                        const sessionId = this.activeSession.id;
                        const res2 = await fetch(this.routeFor('show', sessionId), { headers: this.getHeaders() });
                        if (res2.ok) {
                            const fullData = await res2.json();
                            this.activeSession = fullData;
                            if (this.activeSession.messages) {
                                Object.values(this.activeSession.messages).flat().forEach(m => { m._expanded = false; m._showThinking = false; m._showTools = false; });
                            }
                        }
                    }

                    // Check if any message status changed — reload to show new content
                    let needsReload = false;
                    if (data.messages) {
                        for (const [round, msgs] of Object.entries(data.messages)) {
                            for (const msg of msgs) {
                                const existing = this.activeSession.messages?.[round]?.find(m => m.id === msg.id);
                                if (existing && existing.status !== msg.status) {
                                    if (msg.status === 'completed' || msg.status === 'failed') {
                                        needsReload = true;
                                    }
                                    existing.status = msg.status;
                                    if (msg.error_message) existing.error_message = msg.error_message;
                                }
                            }
                        }
                    }

                    if (data.status === 'completed' || data.status === 'failed') {
                        this.stopPolling();
                        await this.loadSession(this.activeSession.id);
                        await this.loadSessions();
                    } else if (needsReload) {
                        const sessionId = this.activeSession.id;
                        const res2 = await fetch(this.routeFor('show', sessionId), { headers: this.getHeaders() });
                        if (res2.ok) {
                            const fullData = await res2.json();
                            this.activeSession = fullData;
                            if (this.activeSession.messages) {
                                Object.values(this.activeSession.messages).flat().forEach(m => { m._expanded = false; m._showThinking = false; m._showTools = false; });
                            }
                            this.scheduleMermaidRender();
                        }
                    }
                } catch (e) { console.error('Poll failed:', e); }
            },

            async retryFailed() {
                if (!this.activeSession) return;
                try {
                    const res = await fetch(this.routeFor('retry', this.activeSession.id), {
                        method: 'POST',
                        headers: this.getHeaders(true),
                    });
                    if (!res.ok) { const data = await res.json().catch(() => ({})); console.error('Retry failed:', res.status, data); alert(data.message || 'Retry failed'); return; }
                    await this.loadSession(this.activeSession.id);
                } catch (e) { console.error('Retry exception:', e); alert('Retry failed: ' + e.message); }
            },

            async retryAgent(messageId) {
                if (!this.activeSession) return;
                try {
                    const res = await fetch(this.routeFor('retryAgent', this.activeSession.id, messageId), {
                        method: 'POST',
                        headers: this.getHeaders(true),
                    });
                    if (!res.ok) { const data = await res.json().catch(() => ({})); alert(data.message || 'Retry failed'); return; }
                    await this.loadSession(this.activeSession.id);
                } catch (e) { console.error('Agent retry exception:', e); alert('Retry failed: ' + e.message); }
            },

            async retryReport() {
                if (!this.activeSession) return;
                try {
                    const res = await fetch(this.routeFor('retryReport', this.activeSession.id), {
                        method: 'POST',
                        headers: this.getHeaders(true),
                    });
                    if (!res.ok) { const data = await res.json().catch(() => ({})); alert(data.message || 'Retry failed'); return; }
                    await this.loadSession(this.activeSession.id);
                } catch (e) { console.error('Report retry exception:', e); alert('Retry failed: ' + e.message); }
            },

            canRetryReport() {
                if (!this.activeSession || this.activeSession.status !== 'failed') return false;
                const msgs = this.activeSession.messages;
                if (!msgs) return false;
                const allRounds = Object.values(msgs).flat();
                const hasFailed = allRounds.some(m => m.status === 'failed');
                return !hasFailed;
            },

            canRegenerateReport() {
                if (!this.activeSession) return false;
                const status = this.activeSession.status;
                if (status !== 'completed' && status !== 'failed') return false;
                const msgs = this.activeSession.messages;
                if (!msgs) return false;
                const allRounds = Object.values(msgs).flat();
                return allRounds.some(m => m.status === 'completed');
            },

            async regenerateReport() {
                if (!this.activeSession) return;
                if (!confirm('Regenerate the report from available agent data?')) return;
                try {
                    const res = await fetch(this.routeFor('regenerateReport', this.activeSession.id), {
                        method: 'POST',
                        headers: this.getHeaders(true),
                    });
                    if (!res.ok) { const data = await res.json().catch(() => ({})); alert(data.message || 'Regenerate failed'); return; }
                    await this.loadSession(this.activeSession.id);
                } catch (e) { console.error('Regenerate exception:', e); alert('Regenerate failed: ' + e.message); }
            },

            async deleteSession(id) {
                if (!confirm('Delete this discussion session?')) return;
                try {
                    const res = await fetch(this.routeFor('delete', id), {
                        method: 'DELETE',
                        headers: this.getHeaders(true),
                    });
                    if (res.ok) {
                        await this.loadSessions();
                        if (this.activeSession?.id === id) {
                            this.activeSession = null;
                            this.showReport = false;
                            this.showCreateForm = true;
                        }
                    }
                } catch (e) { console.error('Delete failed:', e); }
            },

            openReanalyzeModal() {
                if (!this.activeSession) return;
                this.reanalyzeInstructions = this.activeSession.user_instructions || '';
                this.reanalyzeModel = this.activeSession.model || '';
                this.reanalyzeModeratorModel = this.activeSession.moderator_model || '';
                this.reanalyzeAgents = [...(this.activeSession.selected_agents || [])];
                this.reanalyzeDeepAnalysis = this.activeSession.deep_analysis ?? true;
                this.showReanalyzeModal = true;
            },

            toggleReanalyzeAgent(role) {
                const idx = this.reanalyzeAgents.indexOf(role);
                if (idx === -1) this.reanalyzeAgents.push(role);
                else this.reanalyzeAgents.splice(idx, 1);
            },

            async submitReanalyze() {
                if (this.reanalyzeAgents.length === 0) { alert('Select at least one agent.'); return; }
                this.reanalyzing = true;
                try {
                    const body = {
                        selected_agents: this.reanalyzeAgents,
                    };
                    if (this.reanalyzeInstructions.trim()) body.user_instructions = this.reanalyzeInstructions.trim();
                    if (this.reanalyzeModel) body.model = this.reanalyzeModel;
                    if (this.reanalyzeModeratorModel) body.moderator_model = this.reanalyzeModeratorModel;
                    body.deep_analysis = this.reanalyzeDeepAnalysis;

                    const res = await fetch(this.routeFor('reanalyze', this.activeSession.id), {
                        method: 'POST',
                        headers: this.getHeaders(true),
                        body: JSON.stringify(body),
                    });
                    if (res.ok) {
                        this.showReanalyzeModal = false;
                        await this.loadSession(this.activeSession.id);
                        await this.loadSessions();
                    } else {
                        const data = await res.json();
                        alert(data.message || 'Cannot re-analyze this session.');
                    }
                } catch (e) { console.error('Reanalyze failed:', e); alert('Error: ' + e.message); }
                finally { this.reanalyzing = false; }
            },

            getSortedRounds() {
                if (!this.activeSession?.messages) return [];
                const rounds = new Set();
                Object.values(this.activeSession.messages).flat().forEach(m => rounds.add(m.round));
                return [...rounds].sort((a, b) => a - b);
            },

            getRoundMessages(round) {
                if (!this.activeSession?.messages) return [];
                return (this.activeSession.messages[round] || []).sort((a, b) => {
                    const order = { completed: 0, running: 1, pending: 2, failed: 3 };
                    return (order[a.status] || 0) - (order[b.status] || 0);
                });
            },

            renderMarkdown(text) {
                if (!text) return '';
                if (typeof marked !== 'undefined') {
                    try { return marked.parse(text, { breaks: true, gfm: true }); }
                    catch { return text.replace(/\n/g, '<br>'); }
                }
                return text.replace(/\n/g, '<br>');
            },

            scheduleMermaidRender() {
                requestAnimationFrame(() => {
                    this.$nextTick(() => this.renderMermaidDiagrams());
                });
            },

            async renderMermaidDiagrams() {
                const container = this.$refs.contentContainer;
                if (!container || typeof mermaid === 'undefined') return;

                const blocks = container.querySelectorAll('code.language-mermaid');
                for (const block of blocks) {
                    const pre = block.closest('pre');
                    if (!pre || pre.dataset.mermaidRendered) continue;
                    pre.dataset.mermaidRendered = 'true';

                    try {
                        const id = 'mermaid-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6);
                        const { svg } = await mermaid.render(id, block.textContent);
                        pre.innerHTML = svg;
                        pre.className = 'df-mermaid';
                    } catch (e) {
                        pre.dataset.mermaidRendered = 'error';
                        console.warn('Mermaid render error:', e);
                    }
                }
            },

            formatDate(dateStr) {
                if (!dateStr) return '';
                const d = new Date(dateStr);
                return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            }
        };
    });
});
</script>
@endpush

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
                    <p class="df-session-item__incident" x-show="session.incident" x-html="incidentLink(session.incident, session.incident?.no + (session.incident?.severity ? ' · ' + session.incident?.severity : '') + (session.incident?.title ? ' — ' + session.incident?.title : ''))"></p>
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
                        <span x-show="!activeSession">Discussion Forum</span>
                        <span x-show="activeSession" x-text="activeSession?.title || 'Discussion Forum'"></span>
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
                        <span x-html="incidentLink(activeSession.incident, activeSession.incident.no + (activeSession.incident.title ? ' — ' + activeSession.incident.title : ''))"></span> · Analysis Complete
                    </p>
                    <p class="df-header__subtitle" x-show="activeSession?.status === 'failed' && activeSession?.incident">
                        <span x-html="incidentLink(activeSession.incident, activeSession.incident.no + (activeSession.incident.title ? ' — ' + activeSession.incident.title : ''))"></span> · Failed
                    </p>
                </div>
            </div>

            <div class="df-header__actions">
                <div x-show="activeSession?.status === 'running' || activeSession?.status === 'pending'" class="df-running-indicator">
                    <span class="df-pulse-dot"></span>
                    <span x-text="activeSession?.status === 'pending' ? 'Preparing...' : 'Analyzing · Round ' + (activeSession?.current_round || 1)"></span>
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

            {{-- ===== PENDING / STARTING STATE ===== --}}
            <div x-show="activeSession && activeSession.status === 'pending' && getSortedRounds().length === 0" x-transition class="df-starting-state">
                <div class="df-starting-state__icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <h3 class="df-starting-state__title">Starting Discussion</h3>
                <p class="df-starting-state__desc">Preparing agents and analyzing incident data...</p>
                <div class="df-starting-state__loader">
                    <div class="df-loader df-loader--active"></div>
                    <span>This may take a few seconds</span>
                </div>
            </div>

            {{-- ===== DISCUSSION ROUNDS ===== --}}
            <div x-show="activeSession && !showReport">
                <template x-for="round in getSortedRounds()" :key="round">
                    <section class="df-round">
                        <div class="df-round__header">
                            <div class="df-round__badge" x-text="round"></div>
                            <h3 class="df-round__title" x-text="round === 1 ? 'Initial Analysis' : 'Discussion Round ' + round"></h3>
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
                                            <button x-show="msg.status === 'completed' && msg.content" @click="msg._expanded = !msg._expanded; scheduleMermaidRender()" class="df-msg__toggle">
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
                                        <div x-show="msg.status === 'running'" class="df-msg__state df-msg__state--running">
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
            <div x-show="activeSession && showReport && activeSession?.status === 'completed'" x-transition class="df-report">
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

                <div x-show="activeSession?.final_report_html" x-html="renderMarkdown(activeSession?.final_report_html || '')"
                     class="df-markdown df-report__body">
                </div>

                <div x-show="!activeSession?.final_report_html" class="df-report__loading">
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

<style>
/* ========================================
   Discussion Forum — "The Roundtable" Theme
   ======================================== */

/* --- CSS Custom Properties --- */
.df-app {
    --df-bg: #faf9f7;
    --df-surface: #ffffff;
    --df-surface-hover: #f5f3f0;
    --df-border: #e8e5e0;
    --df-border-light: #f0eeeb;
    --df-text: #1c1917;
    --df-text-secondary: #78716c;
    --df-text-muted: #a8a29e;
    --df-amber-50: #fffbeb;
    --df-amber-100: #fef3c7;
    --df-amber-500: #f59e0b;
    --df-amber-600: #d97706;
    --df-amber-700: #b45309;
    --df-green-50: #f0fdf4;
    --df-green-500: #22c55e;
    --df-green-700: #15803d;
    --df-red-50: #fef2f2;
    --df-red-500: #ef4444;
    --df-red-700: #b91c1c;
    --df-blue-50: #eff6ff;
    --df-blue-500: #3b82f6;
    --df-radius: 10px;
    --df-radius-sm: 6px;
    --df-radius-lg: 14px;
    --df-shadow-sm: 0 1px 2px rgba(0,0,0,0.04);
    --df-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
    --df-shadow-md: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05);
    --df-transition: 0.15s ease;
    --df-sidebar-w: 280px;
}

/* --- Dark mode overrides --- */
:root.dark .df-app {
    --df-bg: #0f1117;
    --df-surface: #1a1d27;
    --df-surface-hover: #242834;
    --df-border: #2e3345;
    --df-border-light: #252a38;
    --df-text: #e4e5ea;
    --df-text-secondary: #9ca0ad;
    --df-text-muted: #6b7085;
    --df-amber-50: rgba(245, 158, 11, 0.08);
    --df-amber-100: rgba(245, 158, 11, 0.15);
    --df-amber-500: #f59e0b;
    --df-amber-600: #d97706;
    --df-amber-700: #fbbf24;
    --df-green-50: rgba(34, 197, 94, 0.08);
    --df-green-500: #22c55e;
    --df-green-700: #4ade80;
    --df-red-50: rgba(239, 68, 68, 0.08);
    --df-red-500: #ef4444;
    --df-red-700: #f87171;
    --df-blue-50: rgba(59, 130, 246, 0.08);
    --df-blue-500: #3b82f6;
    --df-shadow-sm: 0 1px 2px rgba(0,0,0,0.2);
    --df-shadow: 0 1px 3px rgba(0,0,0,0.3), 0 1px 2px rgba(0,0,0,0.2);
    --df-shadow-md: 0 4px 6px rgba(0,0,0,0.35), 0 2px 4px rgba(0,0,0,0.2);
}

:root.dark .df-status-badge--pending { background: #252a38; color: #9ca0ad; }

:root.dark .df-severity-badge--P1 { background: rgba(239, 68, 68, 0.12); color: #f87171; }
:root.dark .df-severity-badge--P2 { background: rgba(245, 158, 11, 0.12); color: #fbbf24; }
:root.dark .df-severity-badge--P3 { background: rgba(59, 130, 246, 0.12); color: #60a5fa; }
:root.dark .df-severity-badge--default { background: #252a38; color: #9ca0ad; }

:root.dark .df-btn--launch { background: #e4e5ea; color: #1a1d27; border-color: #e4e5ea; }
:root.dark .df-btn--launch:hover:not(.df-btn--disabled) { background: #c9cad0; border-color: #c9cad0; }
:root.dark .df-btn--ghost { border-color: #2e3345; }
:root.dark .df-btn--ghost:hover { background: #242834; }

:root.dark .df-select {
    background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%239ca0ad' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3E%3C/svg%3E");
    background-color: #1a1d27;
}

:root.dark .df-agent-card { background: #1a1d27; border-color: #2e3345; }
:root.dark .df-agent-card:hover { border-color: color-mix(in srgb, var(--agent-color, #9ca0ad) 35%, #2e3345); }
:root.dark .df-agent-card__toggle { background: #1a1d27; border-color: #2e3345; }

:root.dark .df-input { background: #1a1d27; border-color: #2e3345; color: #e4e5ea; }
:root.dark .df-input:focus { border-color: var(--df-amber-500); box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); }
:root.dark .df-textarea { background: #1a1d27; border-color: #2e3345; color: #e4e5ea; }
:root.dark .df-textarea:focus { border-color: var(--df-amber-500); box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); }
:root.dark .df-textarea::placeholder { color: #6b7085; }

:root.dark .df-tabs { border-bottom-color: #2e3345; }
:root.dark .df-tab { color: #6b7085; }
:root.dark .df-tab:hover { color: #9ca0ad; background: rgba(255,255,255,0.03); }
:root.dark .df-tab--active { color: #fbbf24; border-bottom-color: #f59e0b; }
:root.dark .df-tab--done { color: #4ade80; }
:root.dark .df-tab--done:hover { color: #6ee7b7; }
:root.dark .df-tab__step { background: #252a38; color: #6b7085; }
:root.dark .df-tab--active .df-tab__step { background: #f59e0b; color: #1a1d27; }
:root.dark .df-tab__step--done { background: #22c55e !important; color: white !important; }
:root.dark .df-tab-actions { border-top-color: #252a38; }

:root.dark .df-dropdown { background: #1a1d27; border-color: #2e3345; }
:root.dark .df-selected-inc { background: rgba(245, 158, 11, 0.06); border-color: rgba(245, 158, 11, 0.15); }
:root.dark .df-selected-inc__id { color: #fbbf24; }

:root.dark .df-modal { background: #1a1d27; border-color: #2e3345; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5), 0 4px 16px rgba(0, 0, 0, 0.3); }

:root.dark .df-modal__header-icon { background: rgba(245, 158, 11, 0.1); }

:root.dark .df-round__badge { background: #e4e5ea; color: #1a1d27; }

:root.dark .df-msg { background: #1a1d27; border-color: #2e3345; }
:root.dark .df-msg:hover { border-color: #3a3f52; }
:root.dark .df-msg__toggle { background: #1a1d27; border-color: #2e3345; }
:root.dark .df-msg__toggle:hover { background: #242834; border-color: #4a506a; }
:root.dark .df-msg__metric { background: #242834; color: #6b7085; }
:root.dark .df-msg__metric--model { background: #242834; color: #9ca0ad; }
:root.dark .df-msg__status-dot--completed { color: #4ade80; }
:root.dark .df-msg__status-dot--completed .df-msg__status-dot__ring { background: #4ade80; }
:root.dark .df-msg__status-dot--running { color: #fbbf24; }
:root.dark .df-msg__status-dot--running .df-msg__status-dot__ring { background: #fbbf24; }
:root.dark .df-msg__status-dot--failed { color: #f87171; }
:root.dark .df-msg__status-dot--failed .df-msg__status-dot__ring { background: #f87171; }
:root.dark .df-msg__role { color: #6b7085; }
:root.dark .df-msg__content { border-top-color: #252a38; }

:root.dark .df-msg__content--collapsed::after { background: linear-gradient(transparent, #1a1d27); }

:root.dark .df-markdown pre { background: #0f1117; border-color: #2e3345; }
:root.dark .df-markdown table { border-color: #2e3345; }
:root.dark .df-markdown thead { background: #242834; }
:root.dark .df-markdown th { border-bottom-color: #2e3345; }
:root.dark .df-markdown td { border-bottom-color: #252a38; }
:root.dark .df-markdown tr:nth-child(even) td { background: rgba(255,255,255, 0.02); }
:root.dark .df-markdown h1 { border-bottom-color: var(--df-amber-500); }
:root.dark .df-markdown h2 { border-bottom-color: #2e3345; }
:root.dark .df-markdown code { background: rgba(245, 158, 11, 0.1); }
:root.dark .df-markdown .df-mermaid { background: #0f1117; border-color: #2e3345; }

:root.dark .df-report__banner { background: linear-gradient(135deg, #1a1d27 0%, #2e3345 100%); }

:root.dark .df-report__body { background: #1a1d27; border-color: #2e3345; }

:root.dark .df-starting-state__icon { background: rgba(245, 158, 11, 0.1); }

:root.dark .df-mobile-overlay { background: rgba(0, 0, 0, 0.6); }

:root.dark .df-session-item--active { background: rgba(245, 158, 11, 0.06) !important; }

:root.dark .df-session-item__delete:hover { background: rgba(239, 68, 68, 0.15); }

.df-btn--delete { color: var(--df-red-500); }
.df-btn--delete:hover { background: var(--df-red-50); color: var(--df-red-700); }
.df-btn--download { color: var(--df-text-secondary); }
.df-btn--download:hover { color: var(--df-green-700); background: var(--df-green-50); }
:root.dark .df-btn--delete { color: #f87171; }
:root.dark .df-btn--delete:hover { background: rgba(239, 68, 68, 0.1); color: #fca5a5; }
:root.dark .df-btn--download:hover { color: #4ade80; background: rgba(34, 197, 94, 0.08); }

/* --- Layout --- */
.df-app {
    display: flex;
    height: calc(100vh - 120px);
    overflow: hidden;
    background: var(--df-bg);
    font-family: 'Instrument Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    color: var(--df-text);
}

/* --- Mobile overlay --- */
.df-mobile-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.3);
    z-index: 40;
    backdrop-filter: blur(2px);
}
.df-mobile-overlay--visible { display: block; }

/* --- Sidebar --- */
.df-sidebar {
    width: var(--df-sidebar-w);
    min-width: var(--df-sidebar-w);
    background: var(--df-surface);
    border-right: 1px solid var(--df-border);
    display: flex;
    flex-direction: column;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.df-sidebar__header {
    padding: 16px;
    border-bottom: 1px solid var(--df-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.df-sidebar__title-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.df-sidebar__icon {
    width: 18px;
    height: 18px;
    color: var(--df-amber-600);
}

.df-sidebar__heading {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--df-text);
    margin: 0;
}

.df-sidebar__list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

/* --- Session items --- */
.df-session-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 12px;
    border-radius: var(--df-radius-sm);
    cursor: pointer;
    border: none;
    background: transparent;
    margin-bottom: 2px;
    transition: background var(--df-transition);
}

.df-session-item:hover { background: var(--df-surface-hover); }
.df-session-item--active { background: var(--df-amber-50) !important; }

.df-session-item__top {
    display: flex;
    align-items: center;
    gap: 6px;
}

.df-session-item__title {
    font-size: 13px;
    font-weight: 600;
    color: var(--df-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.4;
    flex: 1;
    min-width: 0;
}

.df-session-item__delete {
    opacity: 0;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 4px;
    border: none;
    background: none;
    color: var(--df-text-muted);
    cursor: pointer;
    transition: all .15s;
}
.df-session-item:hover .df-session-item__delete { opacity: 1; }
.df-session-item__delete:hover { color: #ef4444; background: rgba(239,68,68,.1); }

.df-session-item__meta {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
}

.df-session-item__date {
    font-size: 11px;
    color: var(--df-text-muted);
}

.df-session-item__user {
    font-size: 10px;
    color: var(--df-text-muted);
    opacity: 0.7;
    margin-left: auto;
}

.df-session-item__model {
    font-size: 10px;
    color: var(--df-text-muted);
    background: var(--df-surface-hover);
    padding: 1px 5px;
    border-radius: 3px;
}
:root.dark .df-session-item__model { background: #242834; color: #6b7085; }

.df-session-item__incident {
    font-size: 11px;
    color: var(--df-text-secondary);
    margin: 2px 0 0;
}

/* --- Status badges --- */
.df-status-badge {
    display: inline-flex;
    align-items: center;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 1px 7px;
    border-radius: 999px;
}
.df-status-badge--completed { background: var(--df-green-50); color: var(--df-green-700); }
.df-status-badge--running { background: var(--df-amber-50); color: var(--df-amber-700); }
.df-status-badge--failed { background: var(--df-red-50); color: var(--df-red-700); }
.df-status-badge--pending { background: #f5f5f4; color: #78716c; }

/* --- Severity badges --- */
.df-severity-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 4px;
}
.df-severity-badge--P1 { background: #fef2f2; color: #991b1b; }
.df-severity-badge--P2 { background: var(--df-amber-50); color: var(--df-amber-700); }
.df-severity-badge--P3 { background: var(--df-blue-50); color: #1e40af; }
.df-severity-badge--default { background: #f5f5f4; color: #57534e; }

/* --- Empty states --- */
.df-empty-sidebar {
    padding: 32px 16px;
    text-align: center;
    color: var(--df-text-muted);
}
.df-empty-sidebar p {
    font-size: 13px;
    font-weight: 600;
    margin: 12px 0 2px;
    color: var(--df-text-secondary);
}
.df-empty-sidebar span {
    font-size: 12px;
}

/* --- Main area --- */
.df-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--df-bg);
}

/* --- Header --- */
.df-header {
    padding: 14px 24px;
    background: var(--df-surface);
    border-bottom: 1px solid var(--df-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.df-header__left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.df-header__title {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
    color: var(--df-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.df-header__subtitle {
    font-size: 12px;
    color: var(--df-text-secondary);
    margin: 2px 0 0;
}

.df-header__actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

/* --- Running indicator --- */
.df-running-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--df-amber-700);
    background: var(--df-amber-50);
    padding: 4px 10px;
    border-radius: 999px;
}

.df-pulse-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--df-amber-500);
    animation: df-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* --- Buttons --- */
.df-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: var(--df-radius-sm);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all var(--df-transition);
    background: transparent;
    color: var(--df-text-secondary);
    font-family: inherit;
}
.df-btn:hover { background: var(--df-surface-hover); }

.df-btn--sm { padding: 4px 10px; font-size: 11px; }

.df-btn--primary {
    background: var(--df-amber-600);
    color: white;
    border-color: var(--df-amber-600);
}
.df-btn--primary:hover { background: var(--df-amber-700); border-color: var(--df-amber-700); }

.df-btn--success {
    background: var(--df-green-500);
    color: white;
    border-color: var(--df-green-500);
}
.df-btn--success:hover { background: var(--df-green-700); border-color: var(--df-green-700); }

.df-btn--danger {
    background: var(--df-red-500);
    color: white;
    border-color: var(--df-red-500);
}
.df-btn--danger:hover { background: var(--df-red-700); border-color: var(--df-red-700); }

.df-btn--ghost {
    background: transparent;
    color: var(--df-text-secondary);
    border-color: var(--df-border);
}
.df-btn--ghost:hover { background: var(--df-surface-hover); }

.df-btn--launch {
    width: 100%;
    padding: 13px 20px;
    font-size: 14px;
    font-weight: 700;
    background: var(--df-text);
    color: white;
    border-color: var(--df-text);
    border-radius: var(--df-radius);
    justify-content: center;
}
.df-btn--launch:hover:not(.df-btn--disabled) { background: #292524; border-color: #292524; }
.df-btn--disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.df-header__actions .df-btn {
    padding: 7px 16px;
}

/* --- Icon button (for ...) --- */
.df-btn--icon {
    padding: 7px 10px;
    min-width: auto;
}

/* --- Dropdown menu --- */
.df-menu {
    position: relative;
}
.df-menu__dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 6px);
    min-width: 220px;
    background: var(--df-surface);
    border: 1px solid var(--df-border);
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12), 0 0 0 1px rgba(0,0,0,0.04);
    z-index: 50;
    padding: 4px;
    overflow: hidden;
}
:root.dark .df-menu__dropdown {
    box-shadow: 0 8px 24px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.06);
}
.df-menu__item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 9px 12px;
    border: none;
    background: none;
    color: var(--df-text);
    font-size: 13.5px;
    font-weight: 500;
    cursor: pointer;
    border-radius: 7px;
    text-decoration: none;
    white-space: nowrap;
    transition: background 0.12s, color 0.12s;
}
.df-menu__item:hover {
    background: var(--df-surface-hover);
}
.df-menu__item svg {
    opacity: 0.5;
    flex-shrink: 0;
}
.df-menu__item:hover svg {
    opacity: 0.8;
}
.df-menu__item--danger {
    color: var(--df-red-600);
}
.df-menu__item--danger:hover {
    background: var(--df-red-50);
    color: var(--df-red-700);
}
:root.dark .df-menu__item--danger { color: #f87171; }
:root.dark .df-menu__item--danger:hover { background: rgba(239,68,68,0.1); color: #fca5a5; }
.df-menu__divider {
    height: 1px;
    background: var(--df-border);
    margin: 4px 8px;
}
.df-menu-enter { animation: dfMenuIn 0.15s ease-out; }
.df-menu-leave { animation: dfMenuIn 0.1s ease-in reverse; }
@keyframes dfMenuIn {
    from { opacity: 0; transform: translateY(-4px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* --- Download button --- */
.df-btn--download:hover { color: var(--df-green-700); background: var(--df-green-50); }
:root.dark .df-btn--download:hover { color: #4ade80; background: rgba(34, 197, 94, 0.08); }

/* --- Mobile toggle --- */
.df-mobile-toggle {
    display: none;
    padding: 6px;
    border: none;
    background: transparent;
    cursor: pointer;
    color: var(--df-text-secondary);
    border-radius: var(--df-radius-sm);
}
.df-mobile-toggle:hover { background: var(--df-surface-hover); }

/* --- Content area --- */
.df-content {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
}

/* --- Create Form --- */
.df-create-form {
    max-width: 660px;
    margin: 0 auto;
    animation: df-fade-up 0.3s ease;
}

.df-create-form__card {
    background: var(--df-surface);
    border-radius: var(--df-radius-lg);
    padding: 28px;
    border: 1px solid var(--df-border);
    box-shadow: var(--df-shadow);
}

.df-create-form__header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 28px;
}

.df-create-form__header-icon {
    flex-shrink: 0;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--df-amber-50);
    color: var(--df-amber-700);
    border-radius: var(--df-radius);
}

.df-create-form__title {
    font-size: 20px;
    font-weight: 700;
    margin: 0 0 4px;
    color: var(--df-text);
}

.df-create-form__desc {
    font-size: 13px;
    color: var(--df-text-secondary);
    margin: 0;
    line-height: 1.5;
}

/* --- Tab navigation --- */
.df-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1.5px solid var(--df-border);
    margin-bottom: 24px;
    position: relative;
}

.df-tab {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 8px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1.5px;
    font-size: 13px;
    font-weight: 500;
    color: var(--df-text-muted);
    cursor: pointer;
    transition: all var(--df-transition);
    font-family: inherit;
}
.df-tab:hover {
    color: var(--df-text-secondary);
    background: var(--df-surface-hover);
}

.df-tab--active {
    color: var(--df-amber-600);
    font-weight: 700;
    border-bottom-color: var(--df-amber-500);
}

.df-tab--done {
    color: var(--df-green-600);
}
.df-tab--done:hover {
    color: var(--df-green-700);
}

.df-tab__step {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--df-border-light);
    color: var(--df-text-muted);
    flex-shrink: 0;
    transition: all var(--df-transition);
}

.df-tab--active .df-tab__step {
    background: var(--df-amber-500);
    color: white;
}

.df-tab__step--done {
    background: var(--df-green-500) !important;
    color: white !important;
}

/* --- Tab panels --- */
.df-tab-panel {
    padding-top: 4px;
    min-height: 200px;
}

/* --- Tab action bar --- */
.df-tab-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px solid var(--df-border-light);
}

/* --- Button variants --- */
.df-btn--full {
    width: 100%;
    justify-content: center;
}

.df-btn--launch-inline {
    width: auto;
}

/* --- Form elements --- */
.df-form-section { margin-bottom: 22px; }

.df-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--df-text-secondary);
    margin-bottom: 8px;
}

.df-search-wrapper {
    position: relative;
}

.df-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--df-text-muted);
    pointer-events: none;
}

.df-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--df-border);
    border-radius: var(--df-radius-sm);
    font-size: 13px;
    font-family: inherit;
    color: var(--df-text);
    background: var(--df-surface);
    transition: border-color var(--df-transition), box-shadow var(--df-transition);
}
.df-input:focus {
    outline: none;
    border-color: var(--df-amber-500);
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
}
.df-input--search { padding-left: 36px; }

.df-select {
    width: 100%;
    padding: 9px 2.5rem 9px 12px;
    border: 1px solid var(--df-border);
    border-radius: var(--df-radius-sm);
    font-size: 13px;
    font-family: inherit;
    color: var(--df-text);
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-color: var(--df-surface);
    background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.5rem center;
    background-repeat: no-repeat;
    background-size: 1.5em 1.5em;
    cursor: pointer;
    transition: border-color var(--df-transition);
}
.df-select:focus {
    outline: none;
    border-color: var(--df-amber-500);
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
}

/* --- Dropdown --- */
.df-dropdown {
    margin-top: 6px;
    border: 1px solid var(--df-border);
    border-radius: var(--df-radius);
    overflow: hidden;
    box-shadow: var(--df-shadow-md);
    max-height: 220px;
    overflow-y: auto;
    background: var(--df-surface);
}

.df-dropdown-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 14px;
    border: none;
    background: transparent;
    cursor: pointer;
    font-family: inherit;
    border-bottom: 1px solid var(--df-border-light);
    transition: background var(--df-transition);
}
.df-dropdown-item:last-child { border-bottom: none; }
.df-dropdown-item:hover { background: var(--df-surface-hover); }

.df-dropdown-item__top {
    display: flex;
    align-items: center;
    gap: 8px;
}

.df-dropdown-item__id {
    font-size: 13px;
    font-weight: 600;
    color: var(--df-text);
}

.df-dropdown-item__title {
    margin: 3px 0 0;
    font-size: 12px;
    color: var(--df-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* --- Selected incident --- */
.df-selected-inc {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
    padding: 10px 12px;
    background: var(--df-amber-50);
    border-radius: var(--df-radius-sm);
    border: 1px solid rgba(245, 158, 11, 0.2);
    animation: df-fade-up 0.2s ease;
}

.df-selected-inc__info {
    display: flex;
    align-items: baseline;
    gap: 6px;
    min-width: 0;
    flex: 1;
}

.df-selected-inc__id {
    font-size: 13px;
    font-weight: 700;
    color: var(--df-amber-700);
    white-space: nowrap;
}

.df-selected-inc__sep {
    color: var(--df-text-muted);
    flex-shrink: 0;
}

.df-selected-inc__title {
    font-size: 13px;
    color: var(--df-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.df-selected-inc__remove {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--df-text-muted);
    cursor: pointer;
    padding: 0 0 0 8px;
    line-height: 1;
    flex-shrink: 0;
}
.df-selected-inc__remove:hover { color: var(--df-text); }

.df-selected-incidents {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.df-token-warning {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 6px;
    font-size: 11px;
    color: #92400e;
    margin-top: 2px;
}
.df-token-warning--ok { background: #ecfdf5; border-color: #6ee7b7; color: #065f46; }
.df-token-warning--warn { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
.df-token-warning--danger { background: #fef2f2; border-color: #fca5a5; color: #991b1b; }
.df-token-warning__icon { width: 14px; height: 14px; flex-shrink: 0; }
.df-token-warning__content { flex: 1; min-width: 0; }
.df-token-bar {
    height: 3px;
    background: var(--df-border-light);
    border-radius: 2px;
    margin-top: 4px;
    overflow: hidden;
}
.df-token-bar__fill {
    height: 100%;
    border-radius: 2px;
    transition: width 0.3s ease, background 0.3s ease;
}
.df-token-warning--ok .df-token-bar__fill { background: var(--df-green-500); }
.df-token-warning--warn .df-token-bar__fill { background: var(--df-amber-500); }
.df-token-warning--danger .df-token-bar__fill { background: var(--df-red-500); }
:root.dark .df-token-bar { background: #252a38; }
:root.dark .df-token-warning { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.2); color: #fbbf24; }
:root.dark .df-token-warning--ok { background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.2); color: #6ee7b7; }
:root.dark .df-token-warning--warn { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2); color: #fbbf24; }
:root.dark .df-token-warning--danger { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); color: #fca5a5; }

.df-agent-chip {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    border: 1.5px solid var(--df-border);
    background: var(--df-bg);
    color: var(--df-text-muted);
    cursor: pointer;
    transition: all 0.15s;
}
.df-agent-chip--selected {
    border-color: var(--agent-color, var(--df-amber-600));
    background: color-mix(in srgb, var(--agent-color, var(--df-amber-600)) 12%, transparent);
    color: var(--agent-color, var(--df-amber-600));
}

/* --- Agent roster --- */
.df-label__count {
    font-weight: 500;
    font-size: 11px;
    color: var(--df-amber-600);
    margin-left: 8px;
}

.df-agent-roster {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
}

.df-agent-card {
    position: relative;
    display: block;
    text-align: left;
    padding: 0;
    border-radius: var(--df-radius);
    font-size: 12px;
    cursor: pointer;
    border: 1.5px solid var(--df-border);
    background: var(--df-surface);
    color: var(--df-text-secondary);
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: inherit;
    overflow: hidden;
}

.df-agent-card:hover {
    border-color: color-mix(in srgb, var(--agent-color, #78716c) 40%, var(--df-border));
    box-shadow: 0 2px 8px color-mix(in srgb, var(--agent-color, #78716c) 8%, transparent);
}

.df-agent-card--selected {
    border-color: color-mix(in srgb, var(--agent-color, #6b7280) 50%, transparent);
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--agent-color, #6b7280) 20%, transparent),
                0 2px 12px color-mix(in srgb, var(--agent-color, #6b7280) 12%, transparent);
}

.df-agent-card__glow {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 100%;
    background: linear-gradient(135deg,
        color-mix(in srgb, var(--agent-color, #6b7280) 4%, transparent) 0%,
        transparent 60%);
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.df-agent-card--selected .df-agent-card__glow { opacity: 1; }

.df-agent-card__inner {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 10px 8px;
    text-align: center;
}

/* --- Avatar --- */
.df-agent-card__avatar {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: white;
    background: var(--agent-color, #78716c);
    box-shadow: 0 1px 3px color-mix(in srgb, var(--agent-color, #78716c) 30%, transparent);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.df-agent-card:hover .df-agent-card__avatar {
    transform: scale(1.08);
    box-shadow: 0 2px 8px color-mix(in srgb, var(--agent-color, #78716c) 35%, transparent);
}

.df-agent-card--selected .df-agent-card__avatar {
    box-shadow: 0 2px 10px color-mix(in srgb, var(--agent-color, #78716c) 40%, transparent);
}

/* --- Body --- */
.df-agent-card__body {
    flex: 1;
    min-width: 0;
    width: 100%;
}

.df-agent-card__header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.df-agent-card__name {
    font-weight: 700;
    color: var(--df-text);
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.3;
    max-width: 100%;
}

/* --- Toggle checkbox --- */
.df-agent-card__toggle {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    border: 1.5px solid var(--df-border);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.2s ease;
    background: var(--df-surface);
    color: white;
    position: absolute;
    top: 6px;
    right: 6px;
}

.df-agent-card:hover .df-agent-card__toggle {
    border-color: var(--df-text-muted);
}

.df-agent-card__toggle--on {
    background: var(--agent-color, var(--df-green-500));
    border-color: var(--agent-color, var(--df-green-500));
    box-shadow: 0 1px 4px color-mix(in srgb, var(--agent-color, #6b7280) 30%, transparent);
}

.df-agent-card__desc {
    margin: 2px 0 0;
    font-size: 10px;
    line-height: 1.4;
    color: var(--df-text-muted);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.df-agent-card__skills {
    display: none;
}

.df-agent-card__skill {
    display: none;
}

.df-agent-card__more {
    display: none;
}

/* --- Form grid --- */
.df-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 18px;
}

.df-form-field {}

.df-hint {
    font-size: 12px;
    color: var(--df-text-muted);
    margin-top: 8px;
}

/* --- Checkbox --- */
.df-checkbox-row {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 13px;
    color: var(--df-text-secondary);
    margin-bottom: 24px;
}

.df-checkbox {
    width: 16px;
    height: 16px;
    accent-color: var(--df-amber-600);
    cursor: pointer;
}

/* --- Textarea --- */
.df-textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--df-border);
    border-radius: var(--df-radius-sm);
    font-size: 13px;
    font-family: inherit;
    color: var(--df-text);
    background: var(--df-surface);
    resize: vertical;
    min-height: 60px;
    transition: border-color var(--df-transition), box-shadow var(--df-transition);
}
.df-textarea:focus {
    outline: none;
    border-color: var(--df-amber-500);
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
}
.df-textarea::placeholder {
    color: var(--df-text-muted);
}

.df-label__hint {
    font-weight: 400;
    font-size: 11px;
    color: var(--df-text-muted);
}

/* --- Instructions banner --- */
.df-instructions-banner {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    margin-bottom: 20px;
    background: var(--df-amber-50);
    border: 1px solid rgba(245, 158, 11, 0.2);
    border-radius: var(--df-radius);
    font-size: 13px;
    color: var(--df-amber-700);
    line-height: 1.5;
}
.df-instructions-banner svg {
    flex-shrink: 0;
    margin-top: 2px;
}

/* --- Modal --- */
.df-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(4px);
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.df-modal {
    background: var(--df-surface);
    border-radius: var(--df-radius-lg);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15), 0 4px 16px rgba(0, 0, 0, 0.08);
    width: 100%;
    max-width: 480px;
    border: 1px solid var(--df-border);
}

.df-modal__header {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 24px 24px 0;
}

.df-modal__header-icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--df-amber-50);
    color: var(--df-amber-700);
    border-radius: var(--df-radius);
}

.df-modal__title {
    font-size: 17px;
    font-weight: 700;
    margin: 0 0 3px;
    color: var(--df-text);
}

.df-modal__desc {
    font-size: 13px;
    color: var(--df-text-secondary);
    margin: 0;
    line-height: 1.5;
}

.df-modal__body {
    padding: 20px 24px;
}

.df-modal__footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 16px 24px;
    border-top: 1px solid var(--df-border);
}

/* --- Discussion Rounds --- */
.df-round {
    margin-bottom: 32px;
    animation: df-fade-up 0.3s ease;
}

.df-round__header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.df-round__badge {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--df-text);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}

.df-round__title {
    font-size: 14px;
    font-weight: 700;
    color: var(--df-text);
    margin: 0;
    white-space: nowrap;
}

.df-round__line {
    flex: 1;
    height: 1px;
    background: var(--df-border);
}

.df-round__messages {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* --- Message card --- */
.df-msg {
    position: relative;
    display: flex;
    background: var(--df-surface);
    border-radius: var(--df-radius);
    border: 1px solid var(--df-border);
    overflow: hidden;
    box-shadow: var(--df-shadow-sm);
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}
.df-msg:hover { box-shadow: var(--df-shadow); border-color: color-mix(in srgb, var(--df-border) 60%, var(--df-text-muted)); }

.df-msg__accent {
    width: 3px;
    flex-shrink: 0;
}

.df-msg--running .df-msg__accent {
    animation: df-accent-pulse 2s ease-in-out infinite;
}

.df-msg__body {
    flex: 1;
    min-width: 0;
}

.df-msg__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 16px 0;
}

.df-msg__author {
    display: flex;
    align-items: center;
    gap: 10px;
}

.df-msg__avatar {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.02em;
    flex-shrink: 0;
}

.df-msg__identity {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.df-msg__name {
    font-size: 13px;
    font-weight: 700;
    color: var(--df-text);
    line-height: 1.2;
}

.df-msg__role {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--df-text-muted);
}

.df-msg__toggle {
    padding: 5px;
    border: 1px solid var(--df-border);
    background: var(--df-surface);
    cursor: pointer;
    color: var(--df-text-muted);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}
.df-msg__toggle:hover {
    color: var(--df-text);
    background: var(--df-surface-hover);
    border-color: var(--df-text-muted);
}

/* --- Meta strip --- */
.df-msg__meta {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 8px 16px;
    flex-wrap: wrap;
}

.df-msg__metric {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 10px;
    font-weight: 600;
    color: var(--df-text-muted);
    padding: 2px 7px;
    background: var(--df-surface-hover);
    border-radius: 4px;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.01em;
}
.df-msg__metric svg {
    opacity: 0.5;
    flex-shrink: 0;
}
.df-msg__metric--model {
    color: var(--df-text-secondary);
    background: var(--df-surface-hover);
    font-weight: 600;
}

/* --- Metric heat colors --- */
.df-metric--ok { color: var(--df-green-700); background: var(--df-green-50); }
.df-metric--ok svg { opacity: 0.7; }
.df-metric--warn { color: var(--df-amber-700); background: var(--df-amber-50); }
.df-metric--warn svg { opacity: 0.7; }
.df-metric--danger { color: var(--df-red-700); background: var(--df-red-50); }
.df-metric--danger svg { opacity: 0.7; }

:root.dark .df-metric--ok { color: #6ee7b7; background: rgba(16, 185, 129, 0.1); }
:root.dark .df-metric--warn { color: #fbbf24; background: rgba(245, 158, 11, 0.1); }
:root.dark .df-metric--danger { color: #fca5a5; background: rgba(239, 68, 68, 0.1); }

/* --- Status dot in meta --- */
.df-msg__status-dot {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-left: auto;
}
.df-msg__status-dot__ring {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.df-msg__status-dot--completed { color: var(--df-green-700); }
.df-msg__status-dot--completed .df-msg__status-dot__ring { background: var(--df-green-500); }
.df-msg__status-dot--running { color: var(--df-amber-700); }
.df-msg__status-dot--running .df-msg__status-dot__ring { background: var(--df-amber-500); animation: df-pulse 1.5s ease-in-out infinite; }
.df-msg__status-dot--failed { color: var(--df-red-700); }
.df-msg__status-dot--failed .df-msg__status-dot__ring { background: var(--df-red-500); }
.df-msg__status-dot--pending { color: var(--df-text-muted); }
.df-msg__status-dot--pending .df-msg__status-dot__ring { background: var(--df-text-muted); opacity: 0.5; }

/* --- Thinking/Reasoning section --- */
.df-msg__thinking {
    padding: 0 16px;
}

.df-msg__thinking-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 7px 10px;
    background: var(--df-surface-hover);
    border: 1px solid var(--df-border-light);
    border-radius: 6px;
    cursor: pointer;
    font-size: 11px;
    font-weight: 600;
    color: var(--df-text-secondary);
    transition: all 0.15s ease;
    font-family: inherit;
}
.df-msg__thinking-toggle:hover {
    background: var(--df-border-light);
    border-color: var(--df-border);
}
.df-msg__thinking-toggle svg:first-child {
    color: var(--df-amber-500);
    flex-shrink: 0;
}
.df-msg__thinking-toggle > span:first-of-type {
    flex: 1;
    text-align: left;
}
.df-msg__thinking-tokens {
    font-size: 10px;
    font-weight: 500;
    color: var(--df-text-muted);
}
.df-msg__thinking-toggle > svg:last-child {
    color: var(--df-text-muted);
    flex-shrink: 0;
}

.df-msg__thinking-content {
    padding: 12px 14px;
    margin-top: 6px;
    background: var(--df-surface-hover);
    border-radius: 6px;
    border-left: 3px solid var(--df-amber-500);
    font-size: 12px;
    line-height: 1.7;
    color: var(--df-text-secondary);
    max-height: 400px;
    overflow-y: auto;
}

:root.dark .df-msg__thinking-toggle {
    background: #242834;
    border-color: #2e3345;
    color: #9ca0ad;
}
:root.dark .df-msg__thinking-toggle:hover {
    background: #2e3345;
    border-color: #3a3f52;
}
:root.dark .df-msg__thinking-toggle svg:first-child { color: #fbbf24; }
:root.dark .df-msg__thinking-content {
    background: #1a1d27;
    border-left-color: var(--df-amber-600);
    color: #9ca0ad;
}

/* --- Message states --- */
.df-msg__state {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 20px 16px;
    font-size: 12px;
}
.df-msg__state--pending { color: var(--df-text-muted); }
.df-msg__state--running { color: var(--df-amber-700); }
.df-msg__state--failed { color: var(--df-red-700); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.df-retry-btn { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 4px; border: 1px solid var(--df-red-300); background: transparent; color: var(--df-red-700); font-size: 12px; cursor: pointer; transition: all 0.15s; }
.df-retry-btn:hover { background: var(--df-red-50); border-color: var(--df-red-500); }
:root.dark .df-retry-btn { border-color: rgba(239,68,68,.3); }
:root.dark .df-retry-btn:hover { background: rgba(239,68,68,.15); border-color: #ef4444; }

/* --- Message content (markdown) --- */
.df-msg__content {
    padding: 0 16px 16px;
    font-size: 13px;
    line-height: 1.75;
    color: var(--df-text);
    max-height: 600px;
    overflow-y: auto;
    border-top: 1px solid var(--df-border-light);
    margin-top: 4px;
    padding-top: 12px;
}
.df-msg__content--collapsed {
    max-height: 200px;
    overflow: hidden;
    position: relative;
}
.df-msg__content--collapsed::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 60px;
    background: linear-gradient(transparent, var(--df-surface));
    pointer-events: none;
}

/* --- Agent reference badges --- */
.df-agent-ref {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 1px 6px 1px 3px;
    background: color-mix(in srgb, var(--ref-color, #6b7280) 8%, transparent);
    border-radius: 4px;
    font-weight: 600;
    color: var(--ref-color, #6b7280);
    white-space: nowrap;
    font-size: 0.95em;
    text-decoration: none;
}
.df-agent-ref__dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}

:root.dark .df-agent-ref {
    background: color-mix(in srgb, var(--ref-color, #6b7280) 12%, transparent);
}

/* Shared markdown rendering (used by message content & report body) */
.df-markdown h1 {
    font-size: 17px; font-weight: 800; margin: 20px 0 10px;
    color: var(--df-text); line-height: 1.25;
    padding-bottom: 6px; border-bottom: 2px solid var(--df-border);
}
.df-markdown h2 {
    font-size: 15px; font-weight: 700; margin: 18px 0 8px;
    color: var(--df-text); line-height: 1.3;
    padding-bottom: 4px; border-bottom: 1px solid var(--df-border-light);
}
.df-markdown h3 {
    font-size: 14px; font-weight: 700; margin: 14px 0 6px;
    color: var(--df-text);
}
.df-markdown h4 {
    font-size: 13px; font-weight: 700; margin: 12px 0 4px;
    color: var(--df-text-secondary);
}
.df-markdown h1:first-child, .df-markdown h2:first-child,
.df-markdown h3:first-child { margin-top: 0; }
.df-markdown p { margin: 8px 0; }
.df-markdown p:first-child { margin-top: 0; }
.df-markdown p:last-child { margin-bottom: 0; }
.df-markdown strong { font-weight: 700; color: var(--df-text); }
.df-markdown em { font-style: italic; color: var(--df-text-secondary); }
.df-markdown ul { list-style: none; padding-left: 0; margin: 8px 0; }
.df-markdown ul li { position: relative; padding-left: 18px; margin-bottom: 5px; }
.df-markdown ul li::before {
    content: ''; position: absolute; left: 4px; top: 8px;
    width: 5px; height: 5px; border-radius: 50%;
    background: var(--df-amber-500);
}
.df-markdown ol { list-style: none; counter-reset: df-counter; padding-left: 0; margin: 8px 0; }
.df-markdown ol li { position: relative; padding-left: 24px; margin-bottom: 5px; counter-increment: df-counter; }
.df-markdown ol li::before {
    content: counter(df-counter); position: absolute; left: 0; top: 0;
    font-size: 11px; font-weight: 800; color: var(--df-amber-700);
    background: var(--df-amber-50); min-width: 18px; height: 18px;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    line-height: 1;
}
.df-markdown li > ul, .df-markdown li > ol { margin: 4px 0; }
.df-markdown blockquote {
    border-left: 3px solid var(--df-amber-500);
    padding: 10px 14px; margin: 12px 0;
    background: var(--df-amber-50);
    border-radius: 0 var(--df-radius-sm) var(--df-radius-sm) 0;
    color: var(--df-text-secondary);
    font-style: italic;
}
.df-markdown code {
    background: rgba(217, 119, 6, 0.08);
    padding: 2px 6px; border-radius: 4px;
    font-size: 12px; color: var(--df-amber-700);
    font-family: 'JetBrains Mono', 'Fira Code', ui-monospace, monospace;
}
.df-markdown pre {
    background: #1c1917; color: #e7e5e4;
    padding: 16px; border-radius: var(--df-radius);
    overflow-x: auto; font-size: 12px; line-height: 1.6;
    margin: 12px 0; border: 1px solid #292524;
}
.df-markdown pre code { background: none; padding: 0; color: inherit; font-size: inherit; }
.df-markdown table {
    width: 100%; border-collapse: collapse; margin: 12px 0;
    font-size: 12px; border-radius: var(--df-radius-sm);
    overflow: hidden; border: 1px solid var(--df-border);
}
.df-markdown thead { background: var(--df-surface-hover); }
.df-markdown th {
    padding: 8px 12px; text-align: left; font-weight: 700;
    color: var(--df-text-secondary); font-size: 11px;
    text-transform: uppercase; letter-spacing: 0.04em;
    border-bottom: 2px solid var(--df-border);
}
.df-markdown td {
    padding: 8px 12px; border-bottom: 1px solid var(--df-border-light);
    color: var(--df-text);
}
.df-markdown tr:last-child td { border-bottom: none; }
.df-markdown tr:nth-child(even) td { background: #faf9f7; }
.df-markdown hr { border: none; height: 1px; background: var(--df-border); margin: 16px 0; }
.df-markdown a {
    color: var(--df-amber-700); text-decoration: none;
    font-weight: 600; border-bottom: 1px dashed var(--df-amber-500);
    transition: all 0.15s;
}
.df-markdown a:hover { border-bottom-style: solid; }
.df-markdown .df-mermaid {
    margin: 14px 0; text-align: center;
    background: #faf9f7; border-radius: var(--df-radius);
    padding: 20px; overflow-x: auto; border: 1px solid var(--df-border);
}
.df-msg__content .df-mermaid svg,
.df-report__body .df-mermaid svg {
    max-width: 100%; height: auto;
}

/* Alert/callout boxes (if AI generates them) */
.df-msg__content > p:first-child > strong:first-child { color: var(--df-amber-700); }

/* --- Loader spinner --- */
.df-loader {
    width: 18px;
    height: 18px;
    border: 2px solid var(--df-border);
    border-top-color: transparent;
    border-radius: 50%;
    flex-shrink: 0;
}
.df-loader--active {
    border-color: var(--df-amber-100);
    border-top-color: var(--df-amber-500);
    animation: df-spin 0.8s linear infinite;
}

/* --- Report view --- */
.df-report {
    max-width: 800px;
    margin: 0 auto;
    animation: df-fade-up 0.3s ease;
}

.df-report__banner {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 28px;
    background: linear-gradient(135deg, #1c1917 0%, #44403c 100%);
    border-radius: var(--df-radius-lg);
    margin-bottom: 24px;
    color: white;
}

.df-report__banner-icon {
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,0.12);
    border-radius: 50%;
    color: var(--df-amber-500);
    flex-shrink: 0;
}

.df-report__title {
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 2px;
}

.df-report__subtitle {
    font-size: 13px;
    margin: 0;
    opacity: 0.7;
}

.df-report__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}

.df-report__meta-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    padding: 3px 8px;
    background: rgba(255,255,255,0.1);
    border-radius: 4px;
    color: rgba(255,255,255,0.7);
}
.df-report__meta-chip svg { width: 12px; height: 12px; opacity: 0.6; }
.df-report__meta-chip--muted { opacity: 0.5; }

.df-report__body {
    font-size: 14px;
    line-height: 1.85;
    color: var(--df-text);
    background: var(--df-surface);
    border-radius: var(--df-radius-lg);
    padding: 32px;
    border: 1px solid var(--df-border);
    box-shadow: var(--df-shadow);
}

/* Report-specific markdown size overrides (inherits .df-markdown base styles) */
.df-report__body.df-markdown h1 { font-size: 22px; margin: 32px 0 14px; padding-bottom: 10px; border-bottom-color: var(--df-amber-500); }
.df-report__body.df-markdown h2 { font-size: 18px; margin: 28px 0 12px; }
.df-report__body.df-markdown h3 { font-size: 15px; margin: 20px 0 8px; }
.df-report__body.df-markdown h4 { font-size: 14px; margin: 16px 0 6px; }
.df-report__body.df-markdown p { margin: 10px 0; }
.df-report__body.df-markdown ul { margin: 10px 0; }
.df-report__body.df-markdown ul li { padding-left: 20px; margin-bottom: 6px; }
.df-report__body.df-markdown ul li::before { top: 9px; width: 6px; height: 6px; }
.df-report__body.df-markdown ol { margin: 10px 0; }
.df-report__body.df-markdown ol li { padding-left: 28px; margin-bottom: 6px; }
.df-report__body.df-markdown ol li::before { top: 1px; font-size: 12px; min-width: 20px; height: 20px; }
.df-report__body.df-markdown blockquote { padding: 12px 16px; margin: 14px 0; }
.df-report__body.df-markdown pre { padding: 18px; font-size: 13px; margin: 14px 0; }
.df-report__body.df-markdown table { font-size: 13px; margin: 14px 0; }
.df-report__body.df-markdown th, .df-report__body.df-markdown td { padding: 10px 14px; }
.df-report__body.df-markdown hr { margin: 20px 0; }

.df-report__loading {
    text-align: center;
    padding: 48px 20px;
    color: var(--df-text-muted);
}
.df-report__loading p { margin: 12px 0 0; font-size: 13px; }

.df-report__footer {
    display: flex;
    justify-content: center;
    gap: 24px;
    margin-top: 24px;
    padding: 16px;
    background: var(--df-surface);
    border: 1px solid var(--df-border);
    border-radius: var(--df-radius);
}
.df-report__footer-item {
    text-align: center;
}
.df-report__footer-label {
    display: block;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--df-text-muted);
    margin-bottom: 2px;
}
.df-report__footer-item strong {
    font-size: 14px;
    color: var(--df-text);
}
:root.dark .df-report__footer { background: #1a1d27; border-color: #2e3345; }
:root.dark .df-report__footer-item strong { color: #e4e5ea; }

/* --- Starting state --- */
.df-starting-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 80px 20px;
    text-align: center;
    animation: df-fade-up 0.4s ease;
}
.df-starting-state__icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: var(--df-amber-50);
    color: var(--df-amber-600);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    animation: df-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
.df-starting-state__title {
    font-size: 20px;
    font-weight: 800;
    color: var(--df-text);
    margin: 0 0 6px;
}
.df-starting-state__desc {
    font-size: 14px;
    color: var(--df-text-secondary);
    margin: 0 0 24px;
}
.df-starting-state__loader {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--df-text-muted);
}

/* --- Animations --- */
@keyframes df-spin { to { transform: rotate(360deg); } }
@keyframes df-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
@keyframes df-accent-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}
@keyframes df-fade-up {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* --- Responsive --- */
@media (max-width: 1023px) {
    .df-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        z-index: 50;
        transform: translateX(-100%);
    }
    .df-sidebar--open { transform: translateX(0); }
    .df-mobile-toggle { display: flex; }
}

@media (max-width: 640px) {
    .df-content { padding: 16px; }
    .df-header { padding: 12px 16px; }
    .df-create-form__card { padding: 20px; }
    .df-form-row { grid-template-columns: 1fr; }
    .df-report__banner { padding: 20px; }
}

{{-- Session sidebar filters --}}
.df-sidebar__filters {
    padding: 8px 12px;
    border-bottom: 1px solid var(--df-border-light);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.df-sidebar__search {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 8px;
    background: var(--df-surface-hover);
    border: 1px solid var(--df-border-light);
    border-radius: 6px;
}
.df-sidebar__search svg { color: var(--df-text-muted); flex-shrink: 0; }
.df-sidebar__search-input {
    flex: 1;
    border: none;
    background: none;
    outline: none;
    font-size: 12px;
    color: var(--df-text);
    font-family: inherit;
}
.df-sidebar__search-input::placeholder { color: var(--df-text-muted); }
.df-sidebar__search-clear {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--df-text-muted);
    font-size: 14px;
    padding: 0 2px;
    line-height: 1;
}
:root.dark .df-sidebar__search { background: #242834; border-color: #2e3345; }
:root.dark .df-sidebar__search-input { color: #e2e4ea; }

.df-sidebar__status-chips {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.df-sidebar__chip {
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    border: 1px solid var(--df-border-light);
    background: transparent;
    color: var(--df-text-secondary);
    cursor: pointer;
    font-family: inherit;
    transition: all 0.15s;
}
.df-sidebar__chip:hover { background: var(--df-surface-hover); }
.df-sidebar__chip--active { background: var(--df-text); color: white; border-color: var(--df-text); }
:root.dark .df-sidebar__chip { border-color: #2e3345; color: #9ca0ad; }
:root.dark .df-sidebar__chip:hover { background: #2e3345; }
:root.dark .df-sidebar__chip--active { background: #e2e4ea; color: #1a1d27; border-color: #e2e4ea; }

{{-- Round expand/collapse all --}}
.df-round__actions {
    display: flex;
    gap: 2px;
    margin-left: auto;
}
.df-round__action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border: 1px solid var(--df-border-light);
    border-radius: 4px;
    background: transparent;
    color: var(--df-text-muted);
    cursor: pointer;
    transition: all 0.15s;
}
.df-round__action-btn:hover { background: var(--df-surface-hover); color: var(--df-text); border-color: var(--df-border); }
:root.dark .df-round__action-btn { border-color: #2e3345; color: #64748b; }
:root.dark .df-round__action-btn:hover { background: #2e3345; color: #e2e4ea; }

{{-- Tool usage display --}}
.df-msg__tools {
    padding: 0 16px;
}
.df-msg__tools-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 7px 10px;
    background: var(--df-surface-hover);
    border: 1px solid var(--df-border-light);
    border-radius: 6px;
    cursor: pointer;
    font-size: 11px;
    font-weight: 600;
    color: var(--df-text-secondary);
    transition: all 0.15s ease;
    font-family: inherit;
}
.df-msg__tools-toggle:hover {
    background: var(--df-border-light);
    border-color: var(--df-border);
}
.df-msg__tools-toggle svg:first-child { color: #6366f1; flex-shrink: 0; }
.df-msg__tools-toggle > span:first-of-type { flex: 1; text-align: left; }
.df-msg__tools-toggle > svg:last-child { color: var(--df-text-muted); flex-shrink: 0; }
.df-msg__tools-content {
    padding: 8px 12px;
    margin-top: 6px;
    background: var(--df-surface-hover);
    border-radius: 6px;
    border-left: 3px solid #6366f1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.df-msg__tool-item {
    display: flex;
    align-items: baseline;
    gap: 8px;
    font-size: 11px;
    line-height: 1.5;
}
.df-msg__tool-name {
    font-weight: 600;
    color: #6366f1;
    white-space: nowrap;
}
.df-msg__tool-args {
    color: var(--df-text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
:root.dark .df-msg__tools-toggle { background: #242834; border-color: #2e3345; color: #9ca0ad; }
:root.dark .df-msg__tools-toggle:hover { background: #2e3345; border-color: #3a3f52; }
:root.dark .df-msg__tools-toggle svg:first-child { color: #818cf8; }
:root.dark .df-msg__tools-content { background: #1a1d27; border-left-color: #818cf8; }
:root.dark .df-msg__tool-name { color: #818cf8; }
:root.dark .df-msg__tool-args { color: #64748b; }
</style>

</x-filament-panels::page>
