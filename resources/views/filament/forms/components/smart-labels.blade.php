@php
    $endpoint = route('ai.suggest-labels');
    $applyEndpoint = route('ai.apply-labels');
    $csrf = csrf_token();
    $defaultModel = config('ai.default_model', 'SMART-MODEL');
@endphp

<div
    x-data="{
        loading: false,
        results: null,
        error: '',
        selected: [],
        showPanel: false,

        async suggest() {
            const fields = ['summary', 'root_cause', 'timeline', 'remark', 'severity', 'incident_type', 'business_category', 'root_cause_category', 'responsible_team', 'title'];
            const payload = {};

            for (const f of fields) {
                try {
                    const val = this.\$wire ? await this.\$wire.get('data.' + f) : '';
                    if (val && String(val).trim()) payload[f] = String(val).trim();
                } catch(e) {}
            }

            if (Object.keys(payload).length === 0) {
                try { new FilamentNotification().title('Smart Labeling').body('Fill in at least summary or root cause first.').warning().send(); } catch(e) { alert('Fill in incident data first.'); }
                return;
            }

            this.loading = true;
            this.error = '';
            this.results = null;

            try {
                const resp = await fetch('{{ $endpoint }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ $csrf }}', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });

                const data = await resp.json();

                if (data.success) {
                    this.results = data;
                    this.selected = [...(data.matched || []), ...(data.suggested || [])];
                    this.showPanel = true;

                    if (this.selected.length === 0) {
                        try { new FilamentNotification().title('Smart Labeling').body('No matching labels found.').info().send(); } catch(e) {}
                    }
                } else {
                    this.error = data.error || 'Failed to get suggestions.';
                    try { new FilamentNotification().title('Smart Labeling').body(this.error).danger().send(); } catch(e) { alert(this.error); }
                }
            } catch(e) {
                this.error = 'Network error. Please try again.';
                try { new FilamentNotification().title('Smart Labeling').body(this.error).danger().send(); } catch(e) {}
            } finally {
                this.loading = false;
            }
        },

        toggle(label) {
            const idx = this.selected.indexOf(label);
            if (idx > -1) { this.selected.splice(idx, 1); }
            else { this.selected.push(label); }
        },

        async applySelected() {
            if (this.selected.length === 0) {
                this.showPanel = false;
                return;
            }

            const matched = (this.results?.matched || []).filter(l => this.selected.includes(l));
            const newLabels = (this.results?.suggested || []).filter(l => this.selected.includes(l));

            try {
                const resp = await fetch('{{ $applyEndpoint }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ $csrf }}', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ matched: matched, new_labels: newLabels }),
                });

                const data = await resp.json();
                if (data.success && data.label_ids) {
                    if (this.\$wire) {
                        const current = await this.\$wire.get('data.labels') || [];
                        const merged = [...new Set([...current, ...data.label_ids.map(String)])];
                        this.\$wire.set('data.labels', merged);
                    }
                    this.showPanel = false;
                    try { new FilamentNotification().title('Smart Labeling').body(this.selected.length + ' labels applied.').success().send(); } catch(e) {}
                }
            } catch(e) {
                try { new FilamentNotification().title('Smart Labeling').body('Failed to apply labels.').danger().send(); } catch(e) {}
            }
        },

        close() {
            this.showPanel = false;
        }
    }"
    style="margin-top: 4px;"
>
    <button
        type="button"
        @click="suggest()"
        class="smart-label-btn"
        :disabled="loading"
    >
        <span x-show="!loading" style="display:inline-flex;align-items:center;gap:6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8 19 13"/><path d="M15 9h.01"/><path d="M17.8 6.2 19 5"/><path d="m3 21 9-9"/><path d="M12.2 6.2 11 5"/>
            </svg>
            Smart Labeling
        </span>
        <span x-show="loading" style="display:inline-flex;align-items:center;gap:6px;">
            <span class="ai-spinner"><span class="ai-spinner__dot"></span><span class="ai-spinner__dot"></span><span class="ai-spinner__dot"></span></span>
            Analyzing...
        </span>
    </button>

    {{-- Results Panel --}}
    <div x-show="showPanel" x-transition x-cloak class="smart-label-panel">
        <div class="smart-label-panel__header">
            <span class="smart-label-panel__title">AI Suggested Labels</span>
            <button type="button" @click="close()" class="smart-label-panel__close">&times;</button>
        </div>

        <div class="smart-label-panel__body">
            {{-- Matched existing labels --}}
            <template x-if="results?.matched?.length > 0">
                <div>
                    <div class="smart-label-panel__section">Existing labels</div>
                    <div class="smart-label-panel__tags">
                        <template x-for="label in results.matched" :key="label">
                            <button
                                type="button"
                                @click="toggle(label)"
                                class="smart-label-tag"
                                :class="{ 'smart-label-tag--selected': selected.includes(label) }"
                                x-text="label"
                            ></button>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Suggested new labels --}}
            <template x-if="results?.suggested?.length > 0">
                <div style="margin-top: 10px;">
                    <div class="smart-label-panel__section">Suggested new labels</div>
                    <div class="smart-label-panel__tags">
                        <template x-for="label in results.suggested" :key="label">
                            <button
                                type="button"
                                @click="toggle(label)"
                                class="smart-label-tag smart-label-tag--new"
                                :class="{ 'smart-label-tag--selected': selected.includes(label) }"
                            >
                                <span x-text="label"></span>
                                <span class="smart-label-tag__badge">new</span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="results && selected.length === 0">
                <div style="color:#94a3b8;font-size:13px;padding:8px 0;">No suggestions. Try adding more incident data.</div>
            </template>
        </div>

        <div class="smart-label-panel__footer">
            <span class="smart-label-panel__count" x-text="selected.length + ' selected'"></span>
            <button type="button" @click="applySelected()" class="smart-label-panel__apply" :disabled="selected.length === 0">
                Apply Selected
            </button>
        </div>
    </div>
</div>

<style>
.smart-label-btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:6px 14px;font-size:13px;font-weight:600;font-family:inherit;
    color:#0d9488;background:#f0fdfa;
    border:1.5px solid #ccfbf1;border-radius:7px;cursor:pointer;
    transition:all .2s;
}
.smart-label-btn:hover:not(:disabled){background:#ccfbf1;border-color:#0d9488}
.smart-label-btn:disabled{opacity:.6;cursor:wait}

.smart-label-panel{
    margin-top:10px;
    background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;
    box-shadow:0 4px 16px rgba(0,0,0,.08);overflow:hidden;
}
.smart-label-panel__header{
    display:flex;align-items:center;justify-content:space-between;
    padding:10px 14px;border-bottom:1px solid #f1f5f9;
}
.smart-label-panel__title{font-size:13px;font-weight:700;color:#0f172a}
.smart-label-panel__close{background:none;border:none;font-size:18px;color:#94a3b8;cursor:pointer;padding:0 4px}
.smart-label-panel__close:hover{color:#0f172a}
.smart-label-panel__body{padding:12px 14px}
.smart-label-panel__section{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.smart-label-panel__tags{display:flex;flex-wrap:wrap;gap:6px}
.smart-label-panel__footer{
    display:flex;align-items:center;justify-content:space-between;
    padding:10px 14px;border-top:1px solid #f1f5f9;background:#f8fafc;
}
.smart-label-panel__count{font-size:12px;color:#64748b}
.smart-label-panel__apply{
    padding:6px 16px;font-size:13px;font-weight:600;font-family:inherit;
    color:#fff;background:linear-gradient(135deg,#0d9488,#0f766e);
    border:none;border-radius:6px;cursor:pointer;transition:all .2s;
}
.smart-label-panel__apply:hover:not(:disabled){background:linear-gradient(135deg,#0f766e,#115e59)}
.smart-label-panel__apply:disabled{opacity:.4;cursor:not-allowed}

.smart-label-tag{
    display:inline-flex;align-items:center;gap:4px;
    padding:5px 12px;font-size:12px;font-weight:500;font-family:inherit;
    color:#475569;background:#f8fafc;
    border:1.5px solid #e2e8f0;border-radius:6px;
    cursor:pointer;transition:all .15s;
}
.smart-label-tag:hover{border-color:#0d9488;background:#f0fdfa}
.smart-label-tag--selected{
    color:#fff;background:#0d9488;border-color:#0d9488;
}
.smart-label-tag--new{border-style:dashed}
.smart-label-tag--new.smart-label-tag--selected{border-style:solid}
.smart-label-tag__badge{
    font-size:9px;font-weight:700;color:#f59e0b;background:#fef3c7;
    padding:1px 5px;border-radius:3px;
}
.smart-label-tag--selected .smart-label-tag__badge{color:#0d9488;background:rgba(255,255,255,.2)}
</style>
