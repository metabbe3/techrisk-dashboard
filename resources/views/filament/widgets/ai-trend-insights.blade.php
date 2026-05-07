@php
    $endpoint = route('ai.analyze-trends');
    $csrf = csrf_token();
    $aiService = app(\App\Services\Ai\AiTextService::class);
    $models = $aiService->getAvailableModels();
    $defaultModel = \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL'));
    $isAvailable = $aiService->isAvailable();
    $hasMultipleModels = count($models) > 1;
@endphp

@if($isAvailable)
<div x-data="{
        trLoading: false,
        trResults: null,
        trError: '',
        trOpen: false,
        trElapsed: 0,
        trTimer: null,
        trSelectedModel: '{{ $defaultModel }}',
        trShowModelPicker: false,

        trNotify(body, type) {
            try { new FilamentNotification().title('Trend Analysis').body(body).status(type).send(); } catch(e) {}
        },

        async trFetch(url, body) {
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

        async analyze(model) {
            if (model) this.trSelectedModel = model;
            this.trError = '';
            this.trShowModelPicker = false;
            this.trLoading = true;
            this.trElapsed = 0;
            this.trTimer = setInterval(() => { this.trElapsed++; }, 1000);

            try {
                const payload = { model: this.trSelectedModel };
                @if(isset($start_date) && isset($end_date))
                try {
                    payload.start_date = '{{ $start_date }}';
                    payload.end_date = '{{ $end_date }}';
                } catch(e) {}
                @endif

                const d = await this.trFetch('{{ $endpoint }}', payload);

                if (d.success) {
                    this.trResults = d;
                    this.trOpen = true;
                    this.trNotify('Trend analysis complete.', 'success');
                } else {
                    this.trError = d.error || 'Analysis failed.';
                    this.trNotify(this.trError, 'warning');
                }
            } catch (e) {
                if (e.message !== '419') {
                    this.trError = e.message || 'Network error.';
                    this.trNotify(this.trError, 'danger');
                }
            } finally {
                clearInterval(this.trTimer);
                this.trLoading = false;
            }
        }
    }"
    x-init="
        const trCleanup = () => clearInterval($data.trTimer);
        document.addEventListener('livewire:navigated', trCleanup);
        $cleanup(() => { clearInterval($data.trTimer); document.removeEventListener('livewire:navigated', trCleanup); });
    "
    style="padding:0;"
>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e2e8f0;">
        <div style="display:flex;align-items:center;gap:8px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
            <span style="font-weight:600;font-size:13px;color:#1e293b;">AI Trend Insights</span>
        </div>
        <button type="button" class="sl-trigger" style="background:linear-gradient(135deg,#6366f1,#4f46e5);padding:4px 12px;font-size:11px;" @click="{{ $hasMultipleModels ? 'trShowModelPicker = !trShowModelPicker' : 'analyze()' }}" :disabled="trLoading">
            <span x-show="!trLoading" style="display:inline-flex;align-items:center;gap:4px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                <span x-text="{{ $hasMultipleModels ? "'Analyze (' + trSelectedModel + ')'" : "'Analyze Trends'" }}"></span>
            </span>
            <span x-show="trLoading" style="display:inline-flex;align-items:center;gap:4px;">
                <svg class="sl-spin" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span x-text="trElapsed + 's'"></span>
            </span>
        </button>
    </div>

    @if($hasMultipleModels)
        <div style="position:relative;padding:0 16px 8px;">
            <div x-show="trShowModelPicker" @click.away="trShowModelPicker = false" x-transition x-cloak class="sl-model-picker" style="position:relative;width:100%;">
                <div class="sl-model-picker__header">Choose Model</div>
                <div class="sl-model-picker__list">
                    @foreach($models as $modelId => $modelName)
                        <button type="button" @click="analyze('{{ $modelId }}')" class="sl-model-picker__item" :class="{'sl-model-picker__item--active': trSelectedModel === '{{ $modelId }}'}">
                            <span>{{ $modelName }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div x-show="trError" x-transition.opacity.duration.200ms class="sl-error" style="margin:8px 16px;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
        <span x-text="trError"></span>
    </div>

    <div x-show="trOpen" x-transition x-cloak style="padding:12px 16px;">
        <template x-if="trResults?.trends?.length > 0">
            <div style="margin-bottom:10px;">
                <div style="font-size:10px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Key Trends</div>
                <ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.8;color:#334155;">
                    <template x-for="(t, i) in trResults.trends" :key="'t-'+i"><li x-text="t"></li></template>
                </ul>
            </div>
        </template>
        <template x-if="trResults?.recurring_issues?.length > 0">
            <div style="margin-bottom:10px;">
                <div style="font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Recurring Issues</div>
                <ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.8;color:#92400e;">
                    <template x-for="(r, i) in trResults.recurring_issues" :key="'ri-'+i"><li x-text="r"></li></template>
                </ul>
            </div>
        </template>
        <template x-if="trResults?.anomalies?.length > 0">
            <div style="margin-bottom:10px;">
                <div style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Anomalies</div>
                <ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.8;color:#991b1b;">
                    <template x-for="(a, i) in trResults.anomalies" :key="'a-'+i"><li x-text="a"></li></template>
                </ul>
            </div>
        </template>
        <template x-if="trResults?.recommendations?.length > 0">
            <div>
                <div style="font-size:10px;font-weight:700;color:#059669;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Recommendations</div>
                <ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.8;color:#065f46;">
                    <template x-for="(r, i) in trResults.recommendations" :key="'rec-'+i"><li x-text="r"></li></template>
                </ul>
            </div>
        </template>
    </div>

    <div x-show="!trOpen && !trLoading && !trError" style="padding:16px;text-align:center;color:#94a3b8;font-size:12px;">
        Click "Analyze Trends" to generate AI-powered insights from your incident data.
    </div>
</div>
@endif
