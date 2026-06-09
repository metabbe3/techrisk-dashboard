window.WarRoomSession = function(routes, routeFor) {
    const utils = window.WarRoomUtils;

    return {
        normalizeMessages(session) {
            if (session.messages) {
                const normalized = {};
                for (const [round, msgs] of Object.entries(session.messages)) {
                    normalized[round] = msgs.map(m => ({
                        ...m,
                        _expanded: false,
                        _showThinking: false,
                        _showTools: false,
                        _streaming: false,
                    }));
                }
                session.messages = normalized;
            }
            return session;
        },

        async loadSession(id) {
            try {
                const res = await fetch(routeFor('show', id), { headers: utils.getHeaders() });
                if (!res.ok) { console.error('Load session failed:', res.status, await res.text()); return; }
                const session = await res.json();
                this.activeSession = this.normalizeMessages(session);
                this.showCreateForm = false;
                this.showReport = false;

                this.startPolling();
                this.scheduleMermaidRender();
            } catch (e) { console.error('Failed to load session:', e); }
        },

        async loadSessions() {
            try {
                const res = await fetch(routes.sessions, { headers: utils.getHeaders() });
                if (!res.ok) { console.error('Load sessions failed:', res.status, await res.text()); return; }
                const data = await res.json();
                this.sessions = data.data || data;
            } catch (e) { console.error('Failed to load sessions:', e); }
        },

        async loadAgents() {
            try {
                const res = await fetch(routes.agents, { headers: utils.getHeaders() });
                if (!res.ok) { console.error('Load agents failed:', res.status, await res.text()); return; }
                this.availableAgents = await res.json();
                this._agentCache = null;
            } catch (e) { console.error('Failed to load agents:', e); }
        },

        startPolling() {
            this.stopPolling();
            if (!this.activeSession) return;
            if (this.activeSession.status !== 'running' && this.activeSession.status !== 'pending') return;

            if (window.Echo) {
                window.Echo.private('war-room.' + this.activeSession.id)
                    .listen('.message.updated', (e) => this.onMessageUpdated(e))
                    .listen('.agent.streaming', (e) => this.onAgentStreaming(e))
                    .listen('.round.completed', (e) => this.onRoundCompleted(e))
                    .listen('.session.completed', (e) => this.onSessionCompleted(e))
                    .listen('.report.streaming', (e) => this.onReportStreaming(e))
                    .listen('.pre-analysis.completed', (e) => this.onPreAnalysisCompleted(e));
            }

            this.pollInterval = setInterval(() => this.poll(), 15000);

            // Request notification permission when watching a running session
            if (Notification.permission === 'default') {
                Notification.requestPermission();
            }
        },

        stopPolling() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
            if (window.Echo && this.activeSession) {
                window.Echo.leave('war-room.' + this.activeSession.id);
            }
            clearTimeout(this._reloadTimer);
        },

        debouncedReload() {
            clearTimeout(this._reloadTimer);
            this._reloadTimer = setTimeout(() => {
                if (this.activeSession) this.loadSession(this.activeSession.id);
            }, 500);
        },

        async onMessageUpdated(e) {
            if (!this.activeSession || this.activeSession.id !== e.session_id) return;
            const round = e.round;
            const msgs = this.activeSession.messages?.[round];
            if (!msgs) return;
            const msg = msgs.find(m => m.id === e.message_id);
            if (msg) {
                msg.status = e.status;
                if (e.error_message) msg.error_message = e.error_message;
            }
        },

        onAgentStreaming(e) {
            if (!this.activeSession || !this.activeSession.messages) return;
            for (const [round, msgs] of Object.entries(this.activeSession.messages)) {
                const msg = msgs.find(m => m.id === e.message_id);
                if (msg) {
                    if (msg.status !== 'completed' && msg.status !== 'failed') {
                        msg.content = (msg.content || '') + e.delta;
                        msg.status = 'running';
                        msg._streaming = true;
                    }
                    break;
                }
            }
        },

        async onRoundCompleted(e) {
            if (!this.activeSession) return;
            this.debouncedReload();
        },

        async onSessionCompleted(e) {
            this.stopPolling();
            const title = this.activeSession?.title || 'Discussion Forum';

            clearTimeout(this._reloadTimer);
            await this.loadSession(this.activeSession.id);
            await this.loadSessions();

            // Browser notification when tab is not focused
            if (document.hidden && Notification.permission === 'granted') {
                const notification = new Notification('Analysis Complete', {
                    body: title + ' has finished.',
                    icon: '/favicon.ico',
                });
                notification.onclick = () => { window.focus(); notification.close(); };
            }
        },

        onReportStreaming(e) {
            if (!this.activeSession) return;
            if (!this.activeSession._streamingReport) {
                this.activeSession._streamingReport = '';
            }
            this.activeSession._streamingReport += e.delta;
            if (this.showReport) {
                this.activeSession._reportStreamingHtml = this.renderMarkdown(this.activeSession._streamingReport);
            }
        },

        onPreAnalysisCompleted(e) {
            if (!this.activeSession || this.activeSession.id !== e.session_id) return;
            this.activeSession.pre_analysis = e.pre_analysis;
        },

        async poll() {
            if (!this.activeSession || (this.activeSession.status !== 'running' && this.activeSession.status !== 'pending')) {
                this.stopPolling();
                return;
            }
            try {
                const res = await fetch(routeFor('poll', this.activeSession.id), { headers: utils.getHeaders() });
                const data = await res.json();
                this.activeSession.status = data.status;
                this.activeSession.current_round = data.current_round;
                this.activeSession.error_message = data.error_message;
                if (data.pre_analysis && !this.activeSession.pre_analysis) {
                    this.activeSession.pre_analysis = data.pre_analysis;
                }

                if (data.status === 'running' && (!this.activeSession.messages || Object.keys(this.activeSession.messages || {}).length === 0)) {
                    const sessionId = this.activeSession.id;
                    const res2 = await fetch(routeFor('show', sessionId), { headers: utils.getHeaders() });
                    if (res2.ok) {
                        const fullData = await res2.json();
                        this.activeSession = this.normalizeMessages(fullData);
                    }
                }

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
                    const res2 = await fetch(routeFor('show', sessionId), { headers: utils.getHeaders() });
                    if (res2.ok) {
                        const fullData = await res2.json();
                        this.activeSession = this.normalizeMessages(fullData);
                        this.scheduleMermaidRender();
                    }
                }
            } catch (e) { console.error('Poll failed:', e); }
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

        get filteredSessions() {
            let list = this.sessions;
            if (this.sessionStatusFilter) {
                list = list.filter(s => s.status === this.sessionStatusFilter);
            }
            if (this.sessionSearch?.trim()) {
                const q = this.sessionSearch.toLowerCase().trim();
                list = list.filter(s =>
                    (s.title || '').toLowerCase().includes(q) ||
                    (s.incident?.no || '').toLowerCase().includes(q) ||
                    (s.incident?.title || '').toLowerCase().includes(q)
                );
            }
            return list;
        },

        get sessionProgress() {
            if (!this.activeSession?.messages) return null;
            const all = Object.values(this.activeSession.messages).flat();
            const total = all.length;
            if (total === 0) return null;
            const completed = all.filter(m => m.status === 'completed').length;
            const failed = all.filter(m => m.status === 'failed').length;
            const running = all.filter(m => m.status === 'running').length;
            const pending = all.filter(m => m.status === 'pending').length;
            return {
                total, completed, failed, running, pending,
                percentage: Math.round((completed + failed) / total * 100),
                currentRound: this.activeSession.current_round || 0,
                maxRounds: this.activeSession.max_rounds || 2,
            };
        },

        getRoundStats(round) {
            const msgs = this.activeSession?.messages?.[round] || [];
            return {
                total: msgs.length,
                completed: msgs.filter(m => m.status === 'completed').length,
                failed: msgs.filter(m => m.status === 'failed').length,
                running: msgs.filter(m => m.status === 'running').length,
                pending: msgs.filter(m => m.status === 'pending').length,
            };
        },
    };
};
