window.WarRoomAgents = function() {
    const colorMap = window.WarRoomUtils.colorMap;
    const hexToRgba = window.WarRoomUtils.hexToRgba;

    return {
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

        getAgentColor(name, alpha = 1) {
            const hex = colorMap[name] || colorMap.gray;
            return alpha === 1 ? hex : hexToRgba(hex, alpha);
        },

        getAgentInitial(name) {
            if (!name) return '?';
            const words = name.trim().split(/\s+/);
            if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
            return name.slice(0, 2).toUpperCase();
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
    };
};
