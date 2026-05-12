@php
    $endpoint = route('ai.analyze-root-cause');
    $applyLabelsEndpoint = route('ai.apply-labels');
    $markEnhancedEndpoint = route('ai.mark-enhanced');
    $csrf = csrf_token();
    $aiService = app(\App\Services\Ai\AiTextService::class);
    $models = $aiService->getAvailableModels();
    $defaultModel = \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL'));
    $isAvailable = $aiService->isAvailable();
    $hasMultipleModels = count($models) > 1;
    $recordId = null;
    try {
        $livewire = \Livewire\Livewire::current();
        if ($livewire && method_exists($livewire, 'getRecord')) {
            $record = $livewire->getRecord();
            $recordId = $record?->id;
        }
    } catch (\Throwable $e) {}
@endphp

@if($isAvailable)
<script>
if (typeof window.aiRootCauseData === 'undefined') {
    window.aiRootCauseData = function(config) {
        return {
            rcaLoading: false,
            rcaResults: null,
            rcaError: '',
            rcaOpen: false,
            rcaElapsed: 0,
            rcaTimer: null,
            rcaSelectedModel: config.defaultModel,
            rcaShowModelPicker: false,
            rcaApplying: false,
            rcaApply: { summary: true, root_cause: true, remark: true, recommendation: false },
            rcaSelectedLabels: [],

            rcaNotify(body, type) {
                try { new FilamentNotification().title('AI Analysis').body(body).status(type).send(); } catch(e) {}
            },

            simpleMd(text) {
                if (!text) return '';
                let html = text
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/^### (.+)$/gm, '<h4 style="font-size:13px;font-weight:700;margin:10px 0 4px;color:#1e293b;">$1</h4>')
                    .replace(/^## (.+)$/gm, '<h3 style="font-size:14px;font-weight:700;margin:12px 0 6px;color:#0f172a;">$1</h3>')
                    .replace(/^# (.+)$/gm, '<h2 style="font-size:15px;font-weight:700;margin:14px 0 6px;color:#0f172a;">$1</h2>')
                    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                    .replace(/`([^`]+)`/g, '<code style="background:#e2e8f0;padding:1px 5px;border-radius:3px;font-size:11px;">$1</code>')
                    .replace(/^\- (.+)$/gm, '<li style="margin-left:16px;">$1</li>')
                    .replace(/^\d+\. (.+)$/gm, '<li style="margin-left:16px;list-style-type:decimal;">$1</li>')
                    .replace(/\n{2,}/g, '</p><p style="margin:6px 0;">')
                    .replace(/\n/g, '<br>');
                return '<p style="margin:6px 0;">' + html + '</p>';
            },

            toggleLabel(label) {
                const idx = this.rcaSelectedLabels.indexOf(label);
                if (idx > -1) { this.rcaSelectedLabels.splice(idx, 1); }
                else { this.rcaSelectedLabels.push(label); }
            },

            isLabelSelected(label) {
                return this.rcaSelectedLabels.includes(label);
            },

            async rcaFetch(url, body) {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                });
                if (r.status === 419) { alert('Session expired. Please refresh.'); throw new Error('419'); }
                if (!r.ok) throw new Error('Server error (HTTP ' + r.status + ')');
                return await r.json();
            },

            async analyze(model) {
                if (model) this.rcaSelectedModel = model;
                this.rcaError = '';
                this.rcaShowModelPicker = false;
                this.rcaLoading = true;
                this.rcaElapsed = 0;
                this.rcaTimer = setInterval(() => { this.rcaElapsed++; }, 1000);

                try {
                    const fields = ['summary','timeline','severity','incident_type','business_category','title'];
                    const payload = {};
                    for (const f of fields) {
                        try {
                            const v = await this.$wire.get('data.' + f);
                            if (v && String(v).trim()) payload[f] = String(v).trim();
                        } catch(err) {}
                    }

                    if (!Object.keys(payload).length) {
                        this.rcaError = 'Fill in at least summary or timeline first.';
                        this.rcaNotify(this.rcaError, 'warning');
                        return;
                    }

                    payload.model = this.rcaSelectedModel;
                    const d = await this.rcaFetch(config.endpoint, payload);

                    if (d.success && (d.root_cause || d.summary)) {
                        this.rcaResults = d;
                        this.rcaApply = { summary: true, root_cause: true, remark: true, recommendation: false };
                        this.rcaSelectedLabels = [...(d.labels_matched || []), ...(d.labels_suggested || [])];
                        this.rcaOpen = true;
                        this.rcaNotify('Analysis complete.', 'success');
                    } else {
                        this.rcaError = d.error || 'No analysis generated. Add more incident details.';
                        this.rcaNotify(this.rcaError, 'warning');
                    }
                } catch (e) {
                    if (e.message !== '419') {
                        this.rcaError = e.message || 'Network error.';
                        this.rcaNotify(this.rcaError, 'danger');
                    }
                } finally {
                    clearInterval(this.rcaTimer);
                    this.rcaLoading = false;
                }
            },

            async applySelected() {
                if (!this.rcaResults) return;
                this.rcaApplying = true;
                try {
                    const appliedFields = {};

                    if (this.rcaApply.summary && this.rcaResults.summary) {
                        this.$wire.set('data.summary', this.rcaResults.summary);
                        appliedFields.summary = this.rcaResults.summary;
                    }
                    if (this.rcaApply.root_cause && this.rcaResults.root_cause) {
                        this.$wire.set('data.root_cause', this.rcaResults.root_cause);
                        appliedFields.root_cause = this.rcaResults.root_cause;
                    }
                    if (this.rcaApply.remark && this.rcaResults.remark) {
                        this.$wire.set('data.remark', this.rcaResults.remark);
                        appliedFields.remark = this.rcaResults.remark;
                    }
                    if (this.rcaApply.recommendation && this.rcaResults.recommendation) {
                        const curRemark = await this.$wire.get('data.remark') || '';
                        const append = (curRemark ? curRemark + '\n\n' : '') + '## Recommendation\n' + this.rcaResults.recommendation;
                        this.$wire.set('data.remark', append);
                        appliedFields.remark = append;
                    }

                    // Mark fields as AI-enhanced
                    const recordId = config.recordId;
                    if (recordId && Object.keys(appliedFields).length > 0) {
                        try {
                            await this.rcaFetch(config.markEnhancedEndpoint, {
                                incident_id: recordId,
                                fields: appliedFields,
                            });
                        } catch(e) { console.warn('Mark enhanced failed:', e); }
                    }

                    const selMatched = (this.rcaResults.labels_matched || []).filter(l => this.rcaSelectedLabels.includes(l));
                    const selSuggested = (this.rcaResults.labels_suggested || []).filter(l => this.rcaSelectedLabels.includes(l));

                    if (selMatched.length > 0 || selSuggested.length > 0) {
                        try {
                            const resp = await this.rcaFetch(config.applyLabelsEndpoint, {
                                matched: selMatched,
                                new_labels: selSuggested,
                            });
                            if (resp.success && resp.label_ids?.length) {
                                const cur = await this.$wire.get('data.labels') || [];
                                const merged = [...new Set([...cur, ...resp.label_ids.map(String)])];
                                this.$wire.set('data.labels', merged);
                            }
                        } catch(e) { console.warn('Label apply failed:', e); }
                    }

                    this.rcaOpen = false;
                    this.rcaNotify('Selected fields applied.', 'success');
                } finally {
                    this.rcaApplying = false;
                }
            }
        };
    };
}
</script>

<div x-data="aiRootCauseData({ endpoint: '{{ $endpoint }}', applyLabelsEndpoint: '{{ $applyLabelsEndpoint }}', markEnhancedEndpoint: '{{ $markEnhancedEndpoint }}', csrf: '{{ $csrf }}', defaultModel: '{{ $defaultModel }}', recordId: '{{ $recordId }}' })"
    x-init="
        const rcaCleanup = () => clearInterval($data.rcaTimer);
        document.addEventListener('livewire:navigated', rcaCleanup);
        const rcaObs = new MutationObserver(() => { if (!$el.isConnected) { rcaCleanup(); rcaObs.disconnect(); } });
        rcaObs.observe($el.parentElement, { childList: true });
    "
    class="smart-label-wrapper"
>
    <div class="sl-trigger-group">
        <button type="button" class="sl-trigger" style="background: linear-gradient(135deg, #7c3aed, #6d28d9);" @click="{{ $hasMultipleModels ? 'rcaShowModelPicker = !rcaShowModelPicker' : 'analyze()' }}" :disabled="rcaLoading">
            <span x-show="!rcaLoading" x-transition class="sl-trigger__idle">
                <svg class="sl-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 0-4 4c0 2 2 3 2 6H14c0-3 2-4 2-6a4 4 0 0 0-4-4Z"/><path d="M10 16h4"/><path d="M10 19h4"/><path d="M10 22h2"/><path d="M12 12v-2"/></svg>
                <span x-text="{{ $hasMultipleModels ? "'AI Analysis (' + rcaSelectedModel + ')'" : "'AI Full Analysis'" }}"></span>
            </span>
            <span x-show="rcaLoading" x-transition class="sl-trigger__loading">
                <svg class="sl-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span x-text="rcaElapsed + 's'"></span>
            </span>
            @if($hasMultipleModels)
                <span class="sl-trigger__chevron" x-show="!rcaLoading">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            @endif
        </button>
        @if($hasMultipleModels)
            <span class="sl-trigger__hint" x-show="!rcaLoading">Select model</span>
        @endif
    </div>

    @if($hasMultipleModels)
        <div style="position:relative;">
            <div x-show="rcaShowModelPicker" @click.away="rcaShowModelPicker = false" x-transition:enter="sl-fade-in" x-transition:leave="sl-fade-out" x-cloak class="sl-model-picker">
                <div class="sl-model-picker__header">Choose Model</div>
                <div class="sl-model-picker__list">
                    @foreach($models as $modelId => $modelName)
                        <button type="button" @click="analyze('{{ $modelId }}')" class="sl-model-picker__item" :class="{'sl-model-picker__item--active': rcaSelectedModel === '{{ $modelId }}'}">
                            <span>{{ $modelName }}</span>
                            <svg x-show="rcaSelectedModel === '{{ $modelId }}'" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div x-show="rcaError" x-transition.opacity.duration.200ms class="sl-error">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
        <span x-text="rcaError"></span>
    </div>

    <div x-show="rcaOpen" x-transition:enter="sl-slide-in" x-transition:enter-start="sl-slide-in-start" x-transition:enter-end="sl-slide-in-end" x-transition:leave="sl-slide-out" x-transition:leave-start="sl-slide-out-start" x-transition:leave-end="sl-slide-out-end" x-cloak class="sl-panel">
        <div class="sl-panel__header" style="background: linear-gradient(135deg, rgba(124,58,237,.06), #f8fafc);">
            <div class="sl-panel__header-left">
                <svg class="sl-icon-sm" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M12 2a4 4 0 0 0-4 4c0 2 2 3 2 6H14c0-3 2-4 2-6a4 4 0 0 0-4-4Z"/><path d="M10 16h4"/><path d="M10 19h4"/><path d="M10 22h2"/></svg>
                <span class="sl-panel__title">AI Full Analysis</span>
            </div>
            <button type="button" @click="rcaOpen=false" class="sl-panel__close">&times;</button>
        </div>
        <div class="sl-panel__body" style="max-height:480px;overflow-y:auto;">
            {{-- Summary --}}
            <template x-if="rcaResults?.summary">
                <div class="sl-section">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:11px;font-weight:600;color:#2563eb;">
                            <input type="checkbox" x-model="rcaApply.summary" style="accent-color:#2563eb;width:14px;height:14px;">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z"/></svg>
                            Summary
                        </label>
                    </div>
                    <div style="font-size:12px;line-height:1.7;color:#334155;background:#eff6ff;padding:10px 12px;border-radius:6px;border:1px solid #bfdbfe;" x-html="simpleMd(rcaResults.summary)"></div>
                </div>
            </template>

            {{-- Root Cause --}}
            <template x-if="rcaResults?.root_cause">
                <div class="sl-section">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:11px;font-weight:600;color:#7c3aed;">
                            <input type="checkbox" x-model="rcaApply.root_cause" style="accent-color:#7c3aed;width:14px;height:14px;">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                            Root Cause Analysis
                        </label>
                    </div>
                    <div style="font-size:12px;line-height:1.7;color:#334155;background:#f5f3ff;padding:10px 12px;border-radius:6px;border:1px solid #ddd6fe;" x-html="simpleMd(rcaResults.root_cause)"></div>
                </div>
            </template>

            {{-- Remark --}}
            <template x-if="rcaResults?.remark">
                <div class="sl-section">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:11px;font-weight:600;color:#7c3aed;">
                            <input type="checkbox" x-model="rcaApply.remark" style="accent-color:#7c3aed;width:14px;height:14px;">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.5"><path d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-3l-4 4Z"/></svg>
                            Remarks
                        </label>
                    </div>
                    <div style="font-size:12px;line-height:1.7;color:#334155;background:#f8fafc;padding:10px 12px;border-radius:6px;border:1px solid #e2e8f0;" x-html="simpleMd(rcaResults.remark)"></div>
                </div>
            </template>

            {{-- Categories --}}
            <template x-if="rcaResults?.categories?.length > 0">
                <div class="sl-section">
                    <div class="sl-section-label" style="color:#7c3aed;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.5"><path d="M4 4h16v16H4z"/><path d="M9 4v16"/><path d="M4 9h16"/></svg>
                        Probable Categories
                    </div>
                    <div class="sl-tags">
                        <template x-for="(cat, idx) in rcaResults.categories" :key="'cat-'+idx">
                            <span class="sl-tag" style="border-left-color:#7c3aed;" :style="'animation-delay:'+(idx*50)+'ms'" x-text="cat"></span>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Contributing Factors --}}
            <template x-if="rcaResults?.contributing_factors?.length > 0">
                <div class="sl-section">
                    <div class="sl-section-label" style="color:#7c3aed;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.5"><path d="m15 3 4 4L7 19H3v-4L15 3Z"/></svg>
                        Contributing Factors
                    </div>
                    <ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.8;color:#475569;">
                        <template x-for="(factor, idx) in rcaResults.contributing_factors" :key="'f-'+idx">
                            <li x-text="factor"></li>
                        </template>
                    </ul>
                </div>
            </template>

            {{-- Recommendation --}}
            <template x-if="rcaResults?.recommendation">
                <div class="sl-section">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:11px;font-weight:600;color:#059669;">
                            <input type="checkbox" x-model="rcaApply.recommendation" style="accent-color:#059669;width:14px;height:14px;">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                            Recommendation (append to Remark)
                        </label>
                    </div>
                    <div style="font-size:12px;line-height:1.7;color:#334155;background:#f0fdf4;padding:10px 12px;border-radius:6px;border:1px solid #bbf7d0;" x-html="simpleMd(rcaResults.recommendation)"></div>
                </div>
            </template>

            {{-- Labels --}}
            <template x-if="rcaResults?.labels_matched?.length > 0 || rcaResults?.labels_suggested?.length > 0">
                <div class="sl-section">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-size:11px;font-weight:600;color:#0d9488;display:flex;align-items:center;gap:5px;">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2.5"><path d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 0 1 0 2.828l-7 7a2 2 0 0 1-2.828 0l-7-7A2 2 0 0 1 3 12V7a4 4 0 0 1 4-4Z"/></svg>
                            Labels (click to toggle)
                        </span>
                        <span style="font-size:10px;color:#94a3b8;" x-text="rcaSelectedLabels.length + ' selected'"></span>
                    </div>
                    <div class="sl-tags" style="gap:6px;">
                        <template x-for="(label, idx) in rcaResults.labels_matched" :key="'lm-'+idx">
                            <button type="button" @click="toggleLabel(label)" class="sl-tag" style="cursor:pointer;border-left-color:#0d9488;transition:all .15s;" :style="isLabelSelected(label) ? 'background:#f0fdfa;color:#0d9488;border:1px solid #99f6e4;' : 'background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;opacity:.6;text-decoration:line-through;'" x-text="label"></button>
                        </template>
                        <template x-for="(label, idx) in rcaResults.labels_suggested" :key="'ls-'+idx">
                            <button type="button" @click="toggleLabel(label)" class="sl-tag" style="cursor:pointer;border-left-color:#f59e0b;transition:all .15s;" :style="isLabelSelected(label) ? 'background:#fffbeb;color:#92400e;border:1px solid #fcd34d;' : 'background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;opacity:.6;text-decoration:line-through;'" x-text="label + ' (new)'"></button>
                        </template>
                    </div>
                </div>
            </template>
        </div>
        <div class="sl-panel__footer">
            <span class="sl-count" x-text="'Apply selected fields'"></span>
            <button type="button" class="sl-apply" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);" :disabled="rcaApplying" @click="applySelected()">
                <template x-if="!rcaApplying">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m4.5 12.75 6 6 9-13.5"/></svg>
                </template>
                <template x-if="rcaApplying">
                    <svg class="sl-spin" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                </template>
                <span x-text="rcaApplying ? 'Applying...' : 'Apply Selected'"></span>
            </button>
        </div>
    </div>
</div>
@endif
