@php
    $aiConfig = $getAiConfig();
    $jsonConfig = json_encode($aiConfig);
@endphp

{{-- Render parent Filament Textarea view normally --}}
@include('filament-forms::components.textarea')

@if($aiConfig['isAvailable'])
    <div
        x-data="{
            cfg: {{ $jsonConfig }},
            loading: false,
            showModal: false,
            selectedModel: '{{ $aiConfig['defaultModel'] }}',
            showModelPicker: false,
            editedText: '',
            modelUsed: '',
            elapsed: 0,
            timer: null,

            findTextarea() {
                let el = this.$el.previousElementSibling;
                while (el) {
                    const ta = el.querySelector && el.querySelector('textarea');
                    if (ta) return ta;
                    el = el.previousElementSibling;
                }
                return null;
            },

            async enhance(model) {
                const val = this.$wire ? await this.$wire.get(this.cfg.statePath) : '';
                const text = (val || '').trim();
                if (!text) {
                    try { new FilamentNotification().title('AI Enhancement').body('Please enter some text first.').warning().send(); } catch(e) { alert('Please enter some text first.'); }
                    return;
                }

                this.loading = true;
                this.elapsed = 0;
                this.showModelPicker = false;
                if (model) this.selectedModel = model;
                this.timer = setInterval(() => { this.elapsed++; }, 1000);

                const textarea = this.findTextarea();
                if (textarea) textarea.classList.add('ai-textarea--processing');

                try {
                    const resp = await fetch(this.cfg.endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.cfg.csrfToken, 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ text: text, field_type: this.cfg.fieldType, model: this.selectedModel }),
                    });

                    if (resp.status === 419) {
                        this.notify('Session expired', 'Please refresh the page and try again.', 'danger');
                        return;
                    }

                    if (!resp.ok && resp.status !== 422) {
                        this.notify('Server Error', 'Request failed (HTTP ' + resp.status + '). Please try again.', 'danger');
                        return;
                    }

                    const data = await resp.json();
                    if (data.success && data.text) {
                        this.editedText = data.text;
                        this.modelUsed = data.model || this.selectedModel;
                        this.showModal = true;
                    } else {
                        this.notify('AI Enhancement Failed', data.error || 'Unknown error occurred.', 'danger');
                    }
                } catch(e) {
                    if (e.name === 'AbortError') {
                        this.notify('Request Cancelled', 'The request was aborted.', 'warning');
                    } else if (e.name === 'TypeError' && e.message.includes('fetch')) {
                        this.notify('Network Error', 'Cannot reach the server. Check your internet connection.', 'danger');
                    } else {
                        this.notify('Error', 'An unexpected error occurred. Please try again.', 'danger');
                    }
                } finally {
                    clearInterval(this.timer);
                    this.loading = false;
                    if (textarea) textarea.classList.remove('ai-textarea--processing');
                }
            },

            notify(title, body, type) {
                try { new FilamentNotification().title(title).body(body).status(type).send(); } catch(e) { alert(title + ': ' + body); }
            },

            accept() {
                if (this.$wire && this.cfg.statePath) {
                    this.$wire.set(this.cfg.statePath, this.editedText);
                }
                this.showModal = false;
                const textarea = this.findTextarea();
                if (textarea) {
                    textarea.classList.add('ai-textarea--updated');
                    setTimeout(() => textarea.classList.remove('ai-textarea--updated'), 1200);
                }
            },

            cancel() {
                this.showModal = false;
                this.editedText = '';
            }
        }"
        class="ai-enhance-bar"
    >
        {{-- Main Button --}}
        <button
            type="button"
            @click="Object.keys(cfg.models).length > 1 ? showModelPicker = !showModelPicker : enhance()"
            class="ai-enhance-btn"
            :class="{ 'ai-enhance-btn--loading': loading }"
            :disabled="loading"
        >
            <span class="ai-enhance-btn__icon" x-show="!loading">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8 19 13"/><path d="M15 9h.01"/><path d="M17.8 6.2 19 5"/><path d="m3 21 9-9"/><path d="M12.2 6.2 11 5"/>
                </svg>
            </span>
            <span class="ai-enhance-btn__spinner" x-show="loading" x-transition>
                <span class="ai-spinner"><span class="ai-spinner__dot"></span><span class="ai-spinner__dot"></span><span class="ai-spinner__dot"></span></span>
            </span>
            <span class="ai-enhance-btn__label" x-text="loading ? 'Enhancing ' + elapsed + 's...' : 'AI Enhance'"></span>
            @if(count($aiConfig['models']) > 1)
                <span class="ai-enhance-btn__chevron" x-show="!loading">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </span>
            @endif
        </button>

        @if(count($aiConfig['models']) > 1)
            <span class="ai-enhance-bar__hint" x-show="!loading">Select model</span>
        @endif

        {{-- Model picker --}}
        @if(count($aiConfig['models']) > 1)
            <div style="position:relative;">
                <div
                    x-show="showModelPicker"
                    @click.away="showModelPicker = false"
                    x-transition:enter="ai-fade-in"
                    x-transition:leave="ai-fade-out"
                    x-cloak
                    class="ai-model-picker"
                >
                    <div class="ai-model-picker__header">Choose Model</div>
                    <template x-for="(name, key) in cfg.models" :key="key">
                        <button
                            type="button"
                            @click="enhance(key)"
                            class="ai-model-picker__item"
                            :class="{ 'ai-model-picker__item--active': selectedModel === key }"
                        >
                            <span x-text="name"></span>
                            <svg x-show="selectedModel === key" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                            </svg>
                        </button>
                    </template>
                </div>
            </div>
        @endif

        {{-- Preview Modal --}}
        <template x-teleport="body">
            <div
                x-show="showModal"
                x-transition:enter="ai-modal-enter"
                x-transition:enter-start="ai-modal-enter-start"
                x-transition:enter-end="ai-modal-enter-end"
                x-transition:leave="ai-modal-leave"
                x-transition:leave-start="ai-modal-leave-start"
                x-transition:leave-end="ai-modal-leave-end"
                class="ai-modal-overlay"
                x-cloak
            >
                <div class="ai-modal-overlay__bg" @click="cancel()"></div>
                <div class="ai-modal">
                    <div class="ai-modal__header">
                        <div class="ai-modal__header-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8 19 13"/><path d="M15 9h.01"/><path d="M17.8 6.2 19 5"/><path d="m3 21 9-9"/><path d="M12.2 6.2 11 5"/>
                            </svg>
                        </div>
                        <div class="ai-modal__header-text">
                            <div class="ai-modal__title" x-text="cfg.promptLabel"></div>
                            <div class="ai-modal__subtitle">Review and edit before applying</div>
                        </div>
                        <span x-show="modelUsed" class="ai-modal__badge" x-text="modelUsed"></span>
                    </div>
                    <div class="ai-modal__body">
                        <textarea x-model="editedText" rows="12" class="ai-modal__textarea"></textarea>
                    </div>
                    <div class="ai-modal__footer">
                        <button type="button" @click="cancel()" class="ai-modal__btn ai-modal__btn--cancel">Cancel</button>
                        <button type="button" @click="accept()" class="ai-modal__btn ai-modal__btn--accept">
                            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Apply Changes
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Scoped Styles --}}
    <style>
    /* ===== Action Bar ===== */
    .ai-enhance-bar{display:flex;align-items:center;gap:10px;margin-top:10px}
    .ai-enhance-bar__hint{font-size:11px;color:#94a3b8;font-weight:400;letter-spacing:.01em}

    /* ===== Button ===== */
    .ai-enhance-btn{
        position:relative;
        display:inline-flex;
        align-items:center;
        gap:8px;
        white-space:nowrap;
        padding:8px 18px;
        font-size:14px;
        font-weight:600;
        font-family:inherit;
        color:#fff;
        background:linear-gradient(135deg,#0d9488 0%,#0f766e 100%);
        border:1px solid rgba(255,255,255,.12);
        border-radius:8px;
        cursor:pointer;
        overflow:hidden;
        transition:all .25s cubic-bezier(.4,0,.2,1);
        box-shadow:0 1px 3px rgba(13,148,136,.25),0 1px 2px rgba(0,0,0,.06),inset 0 1px 0 rgba(255,255,255,.1);
        letter-spacing:.01em;
    }
    .ai-enhance-btn:hover{
        background:linear-gradient(135deg,#0f766e 0%,#115e59 100%);
        box-shadow:0 4px 14px rgba(13,148,136,.35),0 2px 4px rgba(0,0,0,.08),inset 0 1px 0 rgba(255,255,255,.15);
        transform:translateY(-1px);
    }
    .ai-enhance-btn:active{transform:translateY(0);box-shadow:0 1px 2px rgba(13,148,136,.2)}
    .ai-enhance-btn:disabled{cursor:wait;transform:none!important;opacity:.85}

    .ai-enhance-btn--loading{animation:ai-shimmer 2s ease-in-out infinite}
    @keyframes ai-shimmer{
        0%,100%{background:linear-gradient(135deg,#115e59 0%,#134e4a 100%)}
        50%{background:linear-gradient(135deg,#0f766e 0%,#115e59 100%)}
    }

    .ai-enhance-btn__icon{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
    .ai-enhance-btn__chevron{display:inline-flex;align-items:center;opacity:.7;margin-left:2px;flex-shrink:0;transition:transform .2s}
    .ai-enhance-btn__label{display:inline;line-height:1}

    /* ===== Spinner ===== */
    .ai-enhance-btn__spinner{display:inline-flex;align-items:center;flex-shrink:0}
    .ai-spinner{display:inline-flex;gap:4px;align-items:center}
    .ai-spinner__dot{width:5px;height:5px;border-radius:50%;background:rgba(255,255,255,.9);animation:ai-bounce 1.4s ease-in-out infinite}
    .ai-spinner__dot:nth-child(2){animation-delay:.16s}
    .ai-spinner__dot:nth-child(3){animation-delay:.32s}
    @keyframes ai-bounce{0%,80%,100%{transform:scale(.6);opacity:.4}40%{transform:scale(1);opacity:1}}

    /* ===== Textarea Effects ===== */
    .ai-textarea--processing{animation:ai-glow 2s ease-in-out infinite!important}
    @keyframes ai-glow{
        0%,100%{box-shadow:0 0 0 2px rgba(13,148,136,.12)}
        50%{box-shadow:0 0 0 3px rgba(13,148,136,.3),0 0 20px rgba(13,148,136,.08)}
    }
    .ai-textarea--updated{animation:ai-flash 1.2s ease-out forwards!important}
    @keyframes ai-flash{
        0%{box-shadow:0 0 0 3px rgba(13,148,136,.5),0 0 30px rgba(13,148,136,.15)}
        100%{box-shadow:none}
    }

    /* ===== Model Picker ===== */
    .ai-model-picker{
        position:absolute;
        bottom:calc(100% + 10px);
        left:0;
        z-index:50;
        min-width:240px;
        background:#fff;
        border:1px solid #e2e8f0;
        border-radius:12px;
        box-shadow:0 12px 40px rgba(0,0,0,.12),0 2px 8px rgba(0,0,0,.06);
        padding:4px 0;
        overflow:hidden;
    }
    .ai-model-picker__header{padding:10px 14px 6px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em}
    .ai-model-picker__item{
        display:flex;
        align-items:center;
        justify-content:space-between;
        width:100%;
        padding:9px 14px;
        font-size:13px;
        font-weight:500;
        color:#334155;
        background:none;
        border:none;
        cursor:pointer;
        transition:background .15s,color .15s;
        font-family:inherit;
    }
    .ai-model-picker__item:hover{background:#f0fdfa;color:#0d9488}
    .ai-model-picker__item--active{color:#0d9488;font-weight:600;background:#f0fdfa}

    /* ===== Modal ===== */
    .ai-modal-overlay{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px}
    .ai-modal-overlay__bg{position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}

    .ai-modal{
        position:relative;
        width:100%;
        max-width:700px;
        background:#fff;
        border-radius:16px;
        box-shadow:0 25px 65px rgba(0,0,0,.2),0 0 0 1px rgba(0,0,0,.05);
        display:flex;
        flex-direction:column;
        max-height:85vh;
        overflow:hidden;
    }
    .ai-modal__header{display:flex;align-items:center;gap:14px;padding:20px 24px;border-bottom:1px solid #f1f5f9}
    .ai-modal__header-icon{
        width:40px;height:40px;
        display:flex;align-items:center;justify-content:center;
        border-radius:10px;
        background:linear-gradient(135deg,#ccfbf1,#99f6e4);
        flex-shrink:0;
    }
    .ai-modal__header-icon svg{color:#0f766e}
    .ai-modal__header-text{flex:1;min-width:0}
    .ai-modal__title{font-size:16px;font-weight:700;color:#0f172a;line-height:1.3}
    .ai-modal__subtitle{font-size:12px;color:#94a3b8;margin-top:2px}
    .ai-modal__badge{
        margin-left:auto;
        font-size:11px;
        background:#f0fdfa;
        color:#0d9488;
        padding:4px 12px;
        border-radius:999px;
        font-weight:600;
        border:1px solid #ccfbf1;
        flex-shrink:0;
    }
    .ai-modal__body{flex:1;overflow:auto;padding:20px 24px}
    .ai-modal__textarea{
        width:100%;
        border-radius:10px;
        border:1.5px solid #e2e8f0;
        background:#f8fafc;
        padding:16px;
        font-size:14px;
        color:#1e293b;
        line-height:1.7;
        outline:none;
        resize:vertical;
        font-family:inherit;
        transition:border-color .2s,box-shadow .2s;
    }
    .ai-modal__textarea:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.12)}
    .ai-modal__footer{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:16px 24px;border-top:1px solid #f1f5f9;background:#f8fafc}
    .ai-modal__btn{
        display:inline-flex;align-items:center;gap:7px;
        padding:9px 20px;font-size:14px;font-weight:600;
        border-radius:8px;cursor:pointer;
        transition:all .2s;font-family:inherit;
    }
    .ai-modal__btn--cancel{color:#475569;background:#fff;border:1px solid #cbd5e1}
    .ai-modal__btn--cancel:hover{background:#f1f5f9;border-color:#94a3b8}
    .ai-modal__btn--accept{
        color:#fff;
        background:linear-gradient(135deg,#0d9488,#0f766e);
        border:1px solid rgba(255,255,255,.12);
        box-shadow:0 1px 3px rgba(13,148,136,.25);
    }
    .ai-modal__btn--accept:hover{background:linear-gradient(135deg,#0f766e,#115e59);box-shadow:0 4px 12px rgba(13,148,136,.35);transform:translateY(-1px)}

    /* ===== Transitions ===== */
    .ai-fade-in{animation:aiFadeIn .15s ease-out forwards}
    .ai-fade-out{animation:aiFadeOut .1s ease-in forwards}
    @keyframes aiFadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
    @keyframes aiFadeOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(4px)}}

    .ai-modal-enter{animation:aiModalIn .25s cubic-bezier(.4,0,.2,1) forwards}
    .ai-modal-enter-start{opacity:0;transform:scale(.95) translateY(10px)}
    .ai-modal-enter-end{opacity:1;transform:scale(1) translateY(0)}
    .ai-modal-leave{animation:aiModalOut .15s ease-in forwards}
    .ai-modal-leave-start{opacity:1;transform:scale(1)}
    .ai-modal-leave-end{opacity:0;transform:scale(.95) translateY(10px)}
    @keyframes aiModalIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
    @keyframes aiModalOut{from{opacity:1;transform:scale(1)}to{opacity:0;transform:scale(.95) translateY(10px)}}
    </style>
@endif
