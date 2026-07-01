@php
    $endpoint = route('ai.detect-similar');
    $csrf = csrf_token();
    $aiService = app(\App\Services\Ai\AiTextService::class);
    $isAvailable = $aiService->isAvailable();
@endphp

@if($isAvailable)
<div x-data="{
        simLoading: false,
        simResults: null,
        simError: '',
        simOpen: false,
        simElapsed: 0,
        simTimer: null,

        simNotify(body, type) {
            try { new FilamentNotification().title('Similar Incidents').body(body).status(type).send(); } catch(e) {}
        },

        async simFetch(url, body) {
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

        async detect() {
            this.simError = '';
            this.simLoading = true;
            this.simElapsed = 0;
            this.simTimer = setInterval(() => { this.simElapsed++; }, 1000);

            try {
                const fields = ['summary','timeline','severity','incident_type','business_category','root_cause_category','responsible_team','title','root_cause','improvements','classification'];
                const payload = {};
                for (const f of fields) {
                    try {
                        const v = await this.$wire.get('data.' + f);
                        if (v && String(v).trim()) payload[f] = String(v).trim();
                    } catch(err) {}
                }

                try {
                    const id = await this.$wire.get('data.id');
                    if (id) payload.exclude_id = id;
                } catch(err) {}

                if (!Object.keys(payload).length) {
                    this.simError = 'Fill in at least summary or timeline first.';
                    this.simNotify(this.simError, 'warning');
                    return;
                }

                const d = await this.simFetch('{{ $endpoint }}', payload);

                if (d.data.success) {
                    this.simResults = d.data.similar || [];
                    this.simOpen = true;
                    if (!d.data.similar?.length) {
                        this.simNotify('No similar incidents found.', 'info');
                    } else {
                        this.simNotify(d.data.similar.length + ' similar incident(s) found.', 'success');
                    }
                } else {
                    this.simError = d.data.error || 'Detection failed.';
                    this.simNotify(this.simError, 'danger');
                }
            } catch (e) {
                if (e.message !== '419') {
                    this.simError = e.message || 'Network error.';
                    this.simNotify(this.simError, 'danger');
                }
            } finally {
                clearInterval(this.simTimer);
                this.simLoading = false;
            }
        },

        simColor(pct) {
            if (pct >= 0.8) return '#ef4444';
            if (pct >= 0.6) return '#f59e0b';
            return '#3b82f6';
        },

        severityColor(sev) {
            const m = {'P1':'#ef4444','P2':'#f59e0b','P3':'#3b82f6','P4':'#10b981','X1':'#6366f1','X2':'#8b5cf6','X3':'#a855f7','X4':'#c084fc'};
            return m[sev] || '#64748b';
        }
    }"
    x-init="
        const simCleanup = () => clearInterval($data.simTimer);
        document.addEventListener('livewire:navigated', simCleanup);
        const simObs = new MutationObserver(() => { if (!$el.isConnected) { simCleanup(); simObs.disconnect(); } });
        simObs.observe($el.parentElement, { childList: true });
    "
    class="smart-label-wrapper"
>
    <div class="sl-trigger-group">
        <button type="button" class="sl-trigger" style="background: linear-gradient(135deg, #ea580c, #c2410c);" @click="detect()" :disabled="simLoading">
            <span x-show="!simLoading" x-transition class="sl-trigger__idle">
                <svg class="sl-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <span>Find Similar Incidents</span>
            </span>
            <span x-show="simLoading" x-transition class="sl-trigger__loading">
                <svg class="sl-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span x-text="simElapsed + 's'"></span>
            </span>
        </button>
    </div>

    <div x-show="simError" x-transition.opacity.duration.200ms class="sl-error">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
        <span x-text="simError"></span>
    </div>

    <div x-show="simOpen" x-transition:enter="sl-slide-in" x-transition:enter-start="sl-slide-in-start" x-transition:enter-end="sl-slide-in-end" x-transition:leave="sl-slide-out" x-transition:leave-start="sl-slide-out-start" x-transition:leave-end="sl-slide-out-end" x-cloak class="sl-panel">
        <div class="sl-panel__header" style="background: linear-gradient(135deg, rgba(234,88,12,.06), #f8fafc);">
            <div class="sl-panel__header-left">
                <svg class="sl-icon-sm" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <span class="sl-panel__title">Similar Incidents Found</span>
            </div>
            <button type="button" @click="simOpen=false" class="sl-panel__close">&times;</button>
        </div>
        <div class="sl-panel__body">
            <template x-if="simResults && simResults.length > 0">
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <template x-for="(inc, idx) in simResults" :key="'sim-'+inc.id">
                        <div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;background:#fff;animation:sl-tag-in .3s cubic-bezier(.4,0,.2,1) both;" :style="'animation-delay:'+(idx*80)+'ms'">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <a :href="'/admin/incidents/' + inc.id" target="_blank" style="font-size:13px;font-weight:600;color:#0d9488;text-decoration:none;" x-text="inc.no"></a>
                                    <span x-show="inc.severity" style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:3px;color:#fff;" :style="'background:' + severityColor(inc.severity)" x-text="inc.severity"></span>
                                    <span x-show="inc.incident_status" style="font-size:10px;color:#64748b;" x-text="inc.incident_status"></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div style="width:48px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                                        <div :style="'width:'+(inc.similarity*100)+'%;height:100%;background:'+simColor(inc.similarity)+';border-radius:3px;transition:width .5s;'"></div>
                                    </div>
                                    <span style="font-size:11px;font-weight:600;" :style="'color:'+simColor(inc.similarity)" x-text="Math.round(inc.similarity * 100) + '%'"></span>
                                </div>
                            </div>
                            <p x-show="inc.summary" style="font-size:11px;color:#64748b;margin:0 0 4px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;" x-text="inc.summary"></p>
                            <p x-show="inc.reason" style="font-size:11px;color:#475569;margin:0;padding:4px 8px;background:#fffbeb;border-radius:4px;border-left:3px solid #f59e0b;line-height:1.4;" x-text="inc.reason"></p>
                            <div style="margin-top:6px;display:flex;justify-content:flex-end;">
                                <a :href="'/admin/incidents/' + inc.id" target="_blank" style="font-size:11px;color:#0d9488;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:3px;">
                                    Open
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg>
                                </a>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
            <template x-if="simResults && simResults.length === 0">
                <div class="sl-empty">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    No similar incidents found.
                </div>
            </template>
        </div>
        <div class="sl-panel__footer">
            <span class="sl-count" x-text="(simResults?.length || 0) + ' similar incident(s)'"></span>
            <button type="button" @click="simOpen=false" style="font-size:12px;font-weight:600;padding:5px 14px;border:none;border-radius:6px;background:#e2e8f0;color:#334155;cursor:pointer;">Close</button>
        </div>
    </div>
</div>
@endif
