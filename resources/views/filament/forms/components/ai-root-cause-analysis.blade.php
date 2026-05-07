@php
    $endpoint = route('ai.analyze-root-cause');
    $csrf = csrf_token();
    $aiService = app(\App\Services\Ai\AiTextService::class);
    $models = $aiService->getAvailableModels();
    $defaultModel = \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL'));
    $isAvailable = $aiService->isAvailable();
    $hasMultipleModels = count($models) > 1;
@endphp

@if($isAvailable)
<div x-data="{
        rcaLoading: false,
        rcaResults: null,
        rcaError: '',
        rcaOpen: false,
        rcaElapsed: 0,
        rcaTimer: null,
        rcaSelectedModel: '{{ $defaultModel }}',
        rcaShowModelPicker: false,

        rcaNotify(body, type) {
            try { new FilamentNotification().title('Root Cause Analysis').body(body).status(type).send(); } catch(e) {}
        },

        async rcaFetch(url, body) {
            const r = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ $csrf }}','Accept':'application/json'},
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
                const d = await this.rcaFetch('{{ $endpoint }}', payload);

                if (d.success && d.root_cause) {
                    this.rcaResults = d;
                    this.rcaOpen = true;
                    this.rcaNotify('Root cause analysis complete.', 'success');
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

        applyRootCause() {
            if (this.rcaResults?.root_cause) {
                this.$wire.set('data.root_cause', this.rcaResults.root_cause);
                this.rcaOpen = false;
                this.rcaNotify('Root cause applied to form.', 'success');
            }
        }
    }"
    x-init="
        const rcaCleanup = () => clearInterval($data.rcaTimer);
        document.addEventListener('livewire:navigated', rcaCleanup);
        $cleanup(() => { clearInterval($data.rcaTimer); document.removeEventListener('livewire:navigated', rcaCleanup); });
    "
    class="smart-label-wrapper"
>
    <div class="sl-trigger-group">
        <button type="button" class="sl-trigger" style="background: linear-gradient(135deg, #7c3aed, #6d28d9);" @click="{{ $hasMultipleModels ? 'rcaShowModelPicker = !rcaShowModelPicker' : 'analyze()' }}" :disabled="rcaLoading">
            <span x-show="!rcaLoading" x-transition class="sl-trigger__idle">
                <svg class="sl-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 0-4 4c0 2 2 3 2 6H14c0-3 2-4 2-6a4 4 0 0 0-4-4Z"/><path d="M10 16h4"/><path d="M10 19h4"/><path d="M10 22h2"/><path d="M12 12v-2"/></svg>
                <span x-text="{{ $hasMultipleModels ? "'AI Root Cause (' + rcaSelectedModel + ')'" : "'AI Root Cause Analysis'" }}"></span>
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
                <span class="sl-panel__title">AI Root Cause Analysis</span>
            </div>
            <button type="button" @click="rcaOpen=false" class="sl-panel__close">&times;</button>
        </div>
        <div class="sl-panel__body">
            <template x-if="rcaResults?.root_cause">
                <div class="sl-section">
                    <div class="sl-section-label" style="color:#7c3aed;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                        Root Cause
                    </div>
                    <div style="font-size:12px;line-height:1.6;color:#334155;white-space:pre-wrap;background:#f8fafc;padding:8px 10px;border-radius:6px;border:1px solid #e2e8f0;" x-text="rcaResults.root_cause"></div>
                </div>
            </template>

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

            <template x-if="rcaResults?.recommendation">
                <div class="sl-section">
                    <div class="sl-section-label" style="color:#059669;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                        Recommendation
                    </div>
                    <div style="font-size:12px;line-height:1.6;color:#334155;white-space:pre-wrap;background:#f0fdf4;padding:8px 10px;border-radius:6px;border:1px solid #bbf7d0;" x-text="rcaResults.recommendation"></div>
                </div>
            </template>
        </div>
        <div class="sl-panel__footer">
            <span class="sl-count">AI-generated analysis</span>
            <button type="button" class="sl-apply" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);" @click="applyRootCause()">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m4.5 12.75 6 6 9-13.5"/></svg>
                Apply Root Cause
            </button>
        </div>
    </div>
</div>
@endif
