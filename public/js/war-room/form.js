window.WarRoomForm = function(routes, routeFor) {
    const utils = window.WarRoomUtils;

    return {
        async searchIncidents() {
            if (this.incidentSearch.length < 2) { this.incidentResults = []; return; }
            try {
                const res = await fetch(routes.incidentSearch + '?q=' + encodeURIComponent(this.incidentSearch), { headers: utils.getHeaders() });
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
                    const res = await fetch(routes.estimateTokens + '?' + params, { headers: utils.getHeaders() });
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
                    headers: utils.getHeaders(true),
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
                const data = await res.json().catch(() => ({ message: 'Request failed (HTTP ' + res.status + ')' }));
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

        async retryFailed() {
            if (!this.activeSession) return;
            try {
                const res = await fetch(routeFor('retry', this.activeSession.id), {
                    method: 'POST',
                    headers: utils.getHeaders(true),
                });
                if (!res.ok) { const data = await res.json().catch(() => ({})); console.error('Retry failed:', res.status, data); alert(data.message || 'Retry failed'); return; }
                await this.loadSession(this.activeSession.id);
            } catch (e) { console.error('Retry exception:', e); alert('Retry failed: ' + e.message); }
        },

        async retryAgent(messageId) {
            if (!this.activeSession) return;
            try {
                const res = await fetch(routeFor('retryAgent', this.activeSession.id, messageId), {
                    method: 'POST',
                    headers: utils.getHeaders(true),
                });
                if (!res.ok) { const data = await res.json().catch(() => ({})); alert(data.message || 'Retry failed'); return; }
                await this.loadSession(this.activeSession.id);
            } catch (e) { console.error('Agent retry exception:', e); alert('Retry failed: ' + e.message); }
        },

        async retryReport() {
            if (!this.activeSession) return;
            try {
                const res = await fetch(routeFor('retryReport', this.activeSession.id), {
                    method: 'POST',
                    headers: utils.getHeaders(true),
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
                const res = await fetch(routeFor('regenerateReport', this.activeSession.id), {
                    method: 'POST',
                    headers: utils.getHeaders(true),
                });
                if (!res.ok) { const data = await res.json().catch(() => ({})); alert(data.message || 'Regenerate failed'); return; }
                await this.loadSession(this.activeSession.id);
            } catch (e) { console.error('Regenerate exception:', e); alert('Regenerate failed: ' + e.message); }
        },

        async deleteSession(id) {
            if (!confirm('Delete this discussion session?')) return;
            try {
                const res = await fetch(routeFor('delete', id), {
                    method: 'DELETE',
                    headers: utils.getHeaders(true),
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
                const body = { selected_agents: this.reanalyzeAgents };
                if (this.reanalyzeInstructions.trim()) body.user_instructions = this.reanalyzeInstructions.trim();
                if (this.reanalyzeModel) body.model = this.reanalyzeModel;
                if (this.reanalyzeModeratorModel) body.moderator_model = this.reanalyzeModeratorModel;
                body.deep_analysis = this.reanalyzeDeepAnalysis;

                const res = await fetch(routeFor('reanalyze', this.activeSession.id), {
                    method: 'POST',
                    headers: utils.getHeaders(true),
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

        expandAll(round) {
            (this.activeSession?.messages?.[round] || []).forEach(m => m._expanded = true);
            this.scheduleMermaidRender();
        },

        collapseAll(round) {
            (this.activeSession?.messages?.[round] || []).forEach(m => m._expanded = false);
        },

        // Template methods
        async loadTemplates() {
            try {
                const res = await fetch(routes.templatesIndex, { headers: utils.getHeaders() });
                if (res.ok) this.templates = (await res.json()).templates || [];
            } catch (e) { console.error('Failed to load templates:', e); }
        },

        async saveTemplate() {
            if (!this.templateName.trim() || this.selectedAgents.length === 0) return;
            try {
                const res = await fetch(routes.templatesStore, {
                    method: 'POST',
                    headers: utils.getHeaders(true),
                    body: JSON.stringify({
                        name: this.templateName.trim(),
                        selected_agents: this.selectedAgents,
                        max_rounds: this.config.maxRounds,
                        model: this.config.model || null,
                        moderator_model: this.config.moderatorModel || null,
                        enable_web_search: this.config.enableWebSearch,
                        deep_analysis: this.config.deepAnalysis,
                        user_instructions: this.config.userInstructions || null,
                    }),
                });
                if (res.ok) {
                    this.templateName = '';
                    await this.loadTemplates();
                }
            } catch (e) { console.error('Save template failed:', e); }
        },

        applyTemplate(template) {
            this.selectedAgents = [...(template.selected_agents || [])];
            this.config.maxRounds = template.max_rounds || 2;
            this.config.model = template.model || '';
            this.config.moderatorModel = template.moderator_model || '';
            this.config.enableWebSearch = template.enable_web_search || false;
            this.config.deepAnalysis = template.deep_analysis ?? true;
            this.config.userInstructions = template.user_instructions || '';
        },

        async deleteTemplate(id) {
            if (!confirm('Delete this template?')) return;
            try {
                const url = routes.templatesDestroy.replace('__ID__', id);
                const res = await fetch(url, {
                    method: 'DELETE',
                    headers: utils.getHeaders(true),
                });
                if (res.ok) await this.loadTemplates();
            } catch (e) { console.error('Delete template failed:', e); }
        },
    };
};
