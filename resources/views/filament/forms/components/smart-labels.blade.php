@php
    $endpoint = route('ai.suggest-labels');
    $applyEndpoint = route('ai.apply-labels');
    $csrf = csrf_token();
    $aiService = app(\App\Services\Ai\AiTextService::class);
    $models = $aiService->getAvailableModels();
    $defaultModel = \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL'));
    $isAvailable = $aiService->isAvailable();
    $hasMultipleModels = count($models) > 1;
@endphp

<div x-data="{
        slLoading: false,
        slResults: null,
        slError: '',
        slSelected: [],
        slOpen: false,
        slElapsed: 0,
        slTimer: null,
        slSelectedModel: '{{ $defaultModel }}',
        slShowModelPicker: false,

        slNotify(body, type) {
            try { new FilamentNotification().title('Smart Labeling').body(body).status(type).send(); } catch(e) { alert('Smart Labeling: ' + body); }
        },

        async slFetch(url, body) {
            const r = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ $csrf }}','Accept':'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            if (r.status === 419) {
                alert('Session expired. Please refresh the page and try again.');
                throw new Error('419');
            }
            if (!r.ok) {
                const text = await r.text().catch(() => '');
                console.error('[Smart Labels] HTTP ' + r.status, text.substring(0, 500));
                throw new Error('Server error (HTTP ' + r.status + '). Check the console for details.');
            }
            try {
                return await r.json();
            } catch (parseErr) {
                console.error('[Smart Labels] JSON parse error', parseErr);
                throw new Error('Invalid response from server. Check the console for details.');
            }
        },

        async suggest(model) {
            if (model) this.slSelectedModel = model;
            this.slError = '';
            this.slShowModelPicker = false;
            this.slLoading = true;
            this.slElapsed = 0;
            this.slTimer = setInterval(() => { this.slElapsed++; }, 1000);

            try {
                if (! this.$wire) {
                    this.slError = 'No form context. Reload the page.';
                    this.slNotify(this.slError, 'danger');
                    return;
                }

                const fields = ['summary','root_cause','timeline','remark','severity','incident_type','business_category','root_cause_category','responsible_team','title'];
                const payload = {};
                for (const f of fields) {
                    try {
                        const v = await this.$wire.get('data.' + f);
                        if (v && String(v).trim()) payload[f] = String(v).trim();
                    } catch(err) {}
                }

                if (!Object.keys(payload).length) {
                    this.slError = 'Fill in at least summary or root cause first.';
                    this.slNotify(this.slError, 'warning');
                    return;
                }

                payload.model = this.slSelectedModel;

                const d = await this.slFetch('{{ $endpoint }}', payload);
                if (d.data.success) {
                    this.slResults = d.data;
                    this.slSelected = [...(d.data.matched || []), ...(d.data.suggested || [])];
                    this.slOpen = true;
                    if (!d.data.matched?.length && !d.data.suggested?.length) {
                        this.slError = 'No labels found. Try adding more incident details or check AI configuration.';
                    }
                } else {
                    this.slError = d.data.error || 'Failed to get suggestions.';
                    this.slNotify(this.slError, 'danger');
                }
            } catch (e) {
                if (e.message !== '419') {
                    this.slError = e.message || 'Network error. Please try again.';
                    console.error('[Smart Labels] suggest error', e);
                    this.slNotify(this.slError, 'danger');
                }
            } finally {
                clearInterval(this.slTimer);
                this.slLoading = false;
            }
        },

        toggle(label) {
            const i = this.slSelected.indexOf(label);
            i > -1 ? this.slSelected.splice(i, 1) : this.slSelected.push(label);
        },

        async apply() {
            const m = (this.slResults?.matched || []).filter(l => this.slSelected.includes(l));
            const n = (this.slResults?.suggested || []).filter(l => this.slSelected.includes(l));
            try {
                const d = await this.slFetch('{{ $applyEndpoint }}', { matched: m, new_labels: n });
                if (d.data.success && d.data.label_ids) {
                    const cur = await this.$wire.get('data.labels') || [];
                    this.$wire.set('data.labels', [...new Set([...cur, ...d.data.label_ids.map(String)])]);
                    this.slOpen = false;
                    this.slNotify(this.slSelected.length + ' label(s) applied successfully.', 'success');
                }
            } catch (e) {
                if (e.message !== '419') {
                    console.error('[Smart Labels] apply error', e);
                    this.slNotify(e.message || 'Failed to apply labels.', 'danger');
                }
            }
        }
    }"
    x-init="
        const slCleanup = () => clearInterval($data.slTimer);
        document.addEventListener('livewire:navigated', slCleanup);
        const slObs = new MutationObserver(() => { if (!$el.isConnected) { slCleanup(); slObs.disconnect(); } });
        slObs.observe($el.parentElement, { childList: true });
    "
    class="smart-label-wrapper"
>
    <div class="sl-trigger-group">
        <button type="button" class="sl-trigger" @click="{{ $hasMultipleModels ? 'slShowModelPicker = !slShowModelPicker' : 'suggest()' }}" :disabled="slLoading">
            <span x-show="!slLoading" x-transition class="sl-trigger__idle">
                <svg class="sl-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8 19 13"/><path d="M15 9h.01"/><path d="M17.8 6.2 19 5"/><path d="m3 21 9-9"/><path d="M12.2 6.2 11 5"/></svg>
                <span x-text="{{ $hasMultipleModels ? "'Smart Labeling (' + slSelectedModel + ')'" : "'Smart Labeling'" }}"></span>
            </span>
            <span x-show="slLoading" x-transition class="sl-trigger__loading">
                <svg class="sl-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span x-text="slElapsed + 's'"></span>
            </span>
            @if($hasMultipleModels)
                <span class="sl-trigger__chevron" x-show="!slLoading">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            @endif
        </button>
        @if($hasMultipleModels)
            <span class="sl-trigger__hint" x-show="!slLoading">Select model</span>
        @endif
    </div>

    @if($hasMultipleModels)
        <div style="position:relative;">
            <div x-show="slShowModelPicker" @click.away="slShowModelPicker = false" x-transition:enter="sl-fade-in" x-transition:leave="sl-fade-out" x-cloak class="sl-model-picker">
                <div class="sl-model-picker__header">Choose Model</div>
                <div class="sl-model-picker__list">
                    @foreach($models as $modelId => $modelName)
                        <button type="button" @click="suggest('{{ $modelId }}')" class="sl-model-picker__item" :class="{'sl-model-picker__item--active': slSelectedModel === '{{ $modelId }}'}">
                            <span>{{ $modelName }}</span>
                            <svg x-show="slSelectedModel === '{{ $modelId }}'" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div x-show="slError" x-transition.opacity.duration.200ms class="sl-error">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
        <span x-text="slError"></span>
    </div>

    <div x-show="slOpen" x-transition:enter="sl-slide-in" x-transition:enter-start="sl-slide-in-start" x-transition:enter-end="sl-slide-in-end" x-transition:leave="sl-slide-out" x-transition:leave-start="sl-slide-out-start" x-transition:leave-end="sl-slide-out-end" x-cloak class="sl-panel">
        <div class="sl-panel__header">
            <div class="sl-panel__header-left">
                <svg class="sl-icon-sm" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8 19 13"/><path d="M15 9h.01"/><path d="M17.8 6.2 19 5"/><path d="m3 21 9-9"/><path d="M12.2 6.2 11 5"/></svg>
                <span class="sl-panel__title">AI Suggested Labels</span>
            </div>
            <button type="button" @click="slOpen=false" class="sl-panel__close">&times;</button>
        </div>
        <div class="sl-panel__body">
            <template x-if="slResults?.matched?.length > 0">
                <div class="sl-section">
                    <div class="sl-section-label">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m4 12 6 6L20 6"/></svg>
                        Matched labels
                    </div>
                    <div class="sl-tags">
                        <template x-for="(label, idx) in slResults.matched" :key="'m-'+label">
                            <button type="button" @click="toggle(label)" class="sl-tag sl-tag--matched" :class="{'sl-tag--active':slSelected.includes(label)}" :style="'animation-delay:'+(idx*50)+'ms'" x-text="label"></button>
                        </template>
                    </div>
                </div>
            </template>
            <template x-if="slResults?.suggested?.length > 0">
                <div class="sl-section">
                    <div class="sl-section-label sl-section-label--new">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                        Suggested new labels
                    </div>
                    <div class="sl-tags">
                        <template x-for="(label, idx) in slResults.suggested" :key="'s-'+label">
                            <button type="button" @click="toggle(label)" class="sl-tag sl-tag--new" :class="{'sl-tag--active':slSelected.includes(label)}" :style="'animation-delay:'+(idx*50+100)+'ms'">
                                <span x-text="label"></span>
                                <span class="sl-tag__badge">new</span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
            <template x-if="slResults && !slResults?.matched?.length && !slResults?.suggested?.length">
                <div class="sl-empty">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    No labels found. Try adding more incident details.
                </div>
            </template>
        </div>
        <div class="sl-panel__footer">
            <span class="sl-count" x-text="slSelected.length + ' selected'"></span>
            <button type="button" class="sl-apply" :disabled="!slSelected.length" @click="apply()">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m4.5 12.75 6 6 9-13.5"/></svg>
                Apply
            </button>
        </div>
    </div>
</div>
