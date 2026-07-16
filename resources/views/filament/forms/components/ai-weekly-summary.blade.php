@php
    $endpoint = route('ai.weekly-summary');
    $csrf = csrf_token();
    $aiService = app(\App\Services\Ai\AiTextService::class);
    $models = $aiService->getModelsForPicker();
    $defaultModel = \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL'));
    $isAvailable = $aiService->isAvailable();
    $hasMultipleModels = count($models) > 1;
@endphp

@if($isAvailable)
<div x-data="{
        wsLoading: false,
        wsResults: null,
        wsError: '',
        wsOpen: false,
        wsElapsed: 0,
        wsTimer: null,
        wsSelectedModel: '{{ $defaultModel }}',
        wsShowModelPicker: false,

        wsNotify(body, type) {
            try { new FilamentNotification().title('Weekly Summary').body(body).status(type).send(); } catch(e) {}
        },

        async wsFetch(url, body) {
            const r = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ $csrf }}','Accept':'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            if (r.status === 419) { alert('Session expired.'); throw new Error('419'); }
            if (!r.ok) throw new Error('Server error (HTTP ' + r.status + ')');
            return await r.json();
        },

        async generate(model) {
            if (model) this.wsSelectedModel = model;
            this.wsError = '';
            this.wsShowModelPicker = false;
            this.wsLoading = true;
            this.wsElapsed = 0;
            this.wsTimer = setInterval(() => { this.wsElapsed++; }, 1000);

            try {
                const year = await this.$wire.get('selectedYear') || new Date().getFullYear();
                const d = await this.wsFetch('{{ $endpoint }}', { year: parseInt(year), model: this.wsSelectedModel });

                if (d.data.success && d.data.summary) {
                    this.wsResults = d.data;
                    this.wsOpen = true;
                    this.wsNotify('Summary generated.', 'success');
                } else {
                    this.wsError = d.data.error || 'No summary generated.';
                    this.wsNotify(this.wsError, 'warning');
                }
            } catch (e) {
                if (e.message !== '419') {
                    this.wsError = e.message || 'Network error.';
                    this.wsNotify(this.wsError, 'danger');
                }
            } finally {
                clearInterval(this.wsTimer);
                this.wsLoading = false;
            }
        }
    }"
    x-init="
        const wsCleanup = () => clearInterval($data.wsTimer);
        document.addEventListener('livewire:navigated', wsCleanup);
        const wsObs = new MutationObserver(() => { if (!$el.isConnected) { wsCleanup(); wsObs.disconnect(); } });
        wsObs.observe($el.parentElement, { childList: true });
    "
    style="margin-bottom:16px;"
>
    <div class="sl-trigger-group">
        <button type="button" class="sl-trigger" style="background:var(--tr-blue-600);" @click="{{ $hasMultipleModels ? 'wsShowModelPicker = !wsShowModelPicker' : 'generate()' }}" :disabled="wsLoading">
            <span x-show="!wsLoading" x-transition class="sl-trigger__idle">
                <svg class="sl-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                <span x-text="{{ $hasMultipleModels ? "'AI Summary (' + wsSelectedModel + ')'" : "'Generate AI Summary'" }}"></span>
            </span>
            <span x-show="wsLoading" x-transition class="sl-trigger__loading">
                <svg class="sl-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span x-text="wsElapsed + 's'"></span>
            </span>
            @if($hasMultipleModels)
                <span class="sl-trigger__chevron" x-show="!wsLoading"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg></span>
            @endif
        </button>
    </div>

    @if($hasMultipleModels)
        <div style="position:relative;">
            <div x-show="wsShowModelPicker" @click.away="wsShowModelPicker = false" x-transition:enter="sl-fade-in" x-transition:leave="sl-fade-out" x-cloak class="sl-model-picker">
                <div class="sl-model-picker__header">Choose Model</div>
                <div class="sl-model-picker__list">
                    @foreach($models as $modelId => $modelName)
                        <button type="button" @click="generate('{{ $modelId }}')" class="sl-model-picker__item" :class="{'sl-model-picker__item--active': wsSelectedModel === '{{ $modelId }}'}">
                            <span>{{ $modelName }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div x-show="wsError" x-transition.opacity.duration.200ms class="sl-error">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
        <span x-text="wsError"></span>
    </div>

    <div x-show="wsOpen" x-transition:enter="sl-slide-in" x-transition:enter-start="sl-slide-in-start" x-transition:enter-end="sl-slide-in-end" x-transition:leave="sl-slide-out" x-transition:leave-start="sl-slide-out-start" x-transition:leave-end="sl-slide-out-end" x-cloak class="sl-panel">
        <div class="sl-panel__header">
            <div class="sl-panel__header-left">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                <span class="sl-panel__title">AI Executive Summary</span>
            </div>
            <button type="button" @click="wsOpen=false" class="sl-panel__close">&times;</button>
        </div>
        <div class="sl-panel__body">
            <template x-if="wsResults?.summary">
                <div class="sl-section">
                    <div class="sl-section-label" style="color:#2563eb;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg>
                        Summary
                    </div>
                    <div style="font-size:12px;line-height:1.7;color:var(--tr-ink);white-space:pre-wrap;background:var(--tr-surface);padding:10px 12px;border-radius:6px;border:1px solid var(--tr-border);" x-text="wsResults.summary"></div>
                </div>
            </template>
            <template x-if="wsResults?.key_highlights?.length > 0">
                <div class="sl-section">
                    <div class="sl-section-label" style="color:#059669;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                        Key Highlights
                    </div>
                    <ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.8;color:var(--tr-muted);">
                        <template x-for="(h, i) in wsResults.key_highlights" :key="'h-'+i"><li x-text="h"></li></template>
                    </ul>
                </div>
            </template>
            <template x-if="wsResults?.areas_of_concern?.length > 0">
                <div class="sl-section">
                    <div class="sl-section-label" style="color:#dc2626;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                        Areas of Concern
                    </div>
                    <ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.8;color:var(--tr-red-700);">
                        <template x-for="(c, i) in wsResults.areas_of_concern" :key="'c-'+i"><li x-text="c"></li></template>
                    </ul>
                </div>
            </template>
            <template x-if="wsResults?.root_cause_insights?.length > 0">
                <div class="sl-section">
                    <div class="sl-section-label" style="color:#d97706;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="M11 8v6"/><path d="M8 11h6"/></svg>
                        Root Cause Insights
                    </div>
                    <ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.8;color:var(--tr-amber-700);">
                        <template x-for="(r, i) in wsResults.root_cause_insights" :key="'rc-'+i"><li x-text="r"></li></template>
                    </ul>
                </div>
            </template>
            <template x-if="wsResults?.recommendation">
                <div class="sl-section">
                    <div class="sl-section-label" style="color:#7c3aed;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.5"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2Z"/></svg>
                        Recommendation
                    </div>
                    <div style="font-size:12px;line-height:1.6;color:var(--tr-ink);white-space:pre-wrap;background:var(--tr-violet-bg);padding:8px 10px;border-radius:6px;border:1px solid var(--tr-border);" x-text="wsResults.recommendation"></div>
                </div>
            </template>
        </div>
        <div class="sl-panel__footer">
            <span class="sl-count">AI-generated summary</span>
            <button type="button" @click="wsOpen=false" style="font-size:12px;font-weight:600;padding:5px 14px;border:none;border-radius:6px;background:var(--tr-border);color:var(--tr-ink);cursor:pointer;">Close</button>
        </div>
    </div>
</div>
@endif
