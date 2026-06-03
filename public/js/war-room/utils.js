window.WarRoomUtils = {
    colorMap: {
        blue: '#3b82f6', indigo: '#6366f1', purple: '#8b5cf6', green: '#22c55e',
        teal: '#14b8a6', cyan: '#06b6d4', red: '#ef4444', orange: '#f97316',
        amber: '#f59e0b', pink: '#ec4899', emerald: '#10b981', gray: '#6b7280',
        fuchsia: '#d946ef', rose: '#f43f5e', sky: '#0ea5e9',
        violet: '#8b5cf6', yellow: '#eab308', lime: '#84cc16',
    },

    hexToRgba(hex, alpha) {
        const r = parseInt(hex.slice(1, 3), 16);
        const g = parseInt(hex.slice(3, 5), 16);
        const b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
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

    getHeaders(withContentType = false) {
        const headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        };
        if (withContentType) headers['Content-Type'] = 'application/json';
        return headers;
    },

    formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    },

    initMermaid() {
        if (typeof mermaid !== 'undefined') {
            mermaid.initialize({
                startOnLoad: false,
                theme: document.documentElement.classList.contains('dark') ? 'dark' : 'default',
            });
        }
    },
};
