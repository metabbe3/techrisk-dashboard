@php
    use App\Enums\FundStatus;
    use App\Enums\IncidentStatus;
    use App\Enums\Severity;

    $inc = $this->record;
    $sevColor = Severity::tryFrom($inc->severity)?->color() ?? 'gray';
    $statusColor = IncidentStatus::tryFrom($inc->incident_status)?->color() ?? 'gray';
    $fundColor = $inc->fund_status ? FundStatus::tryFrom($inc->fund_status)?->color() ?? 'gray' : null;

    $sevGradient = match($sevColor) {
        'danger' => 'from-red-600 to-red-500',
        'warning' => 'from-amber-600 to-amber-500',
        'info' => 'from-blue-600 to-blue-500',
        'success' => 'from-emerald-600 to-emerald-500',
        default => 'from-slate-600 to-slate-500',
    };

    $hasFinancials = $inc->potential_fund_loss > 0 || $inc->fund_loss > 0 || $inc->recovered_fund > 0;
    $inc->loadMissing(['pic', 'labels', 'incidentType', 'latestStatusUpdate']);
@endphp

<div class="fi-page">
    {{-- Hero Header --}}
    <div class="rounded-xl overflow-hidden mb-6 bg-gradient-to-r {{ $sevGradient }} shadow-lg">
        <div class="px-6 py-5">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="text-white/70 text-xs font-mono tracking-wider">{{ $inc->no }}</span>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-white/20 text-white backdrop-blur-sm">{{ $inc->severity }}</span>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-white/20 text-white backdrop-blur-sm">{{ $inc->incident_status }}</span>
                @if($inc->fund_status)
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-white/20 text-white backdrop-blur-sm">{{ $inc->fund_status }}</span>
                @endif
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-white/10 text-white/80 backdrop-blur-sm">{{ $inc->incident_type }}</span>
                @if($inc->classification)
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-white/10 text-white/80 backdrop-blur-sm">{{ $inc->classification }}</span>
                @endif
            </div>
            <h1 class="text-xl font-bold text-white leading-tight mb-3">{{ $inc->title }}</h1>
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-white/80 text-sm">
                @if($inc->pic)
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        {{ $inc->pic->name }}
                    </span>
                @endif
                @if($inc->incident_date)
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        {{ $inc->incident_date->format('d M Y, H:i') }}
                    </span>
                @endif
                @if($inc->incident_source)
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        {{ $inc->incident_source }}
                    </span>
                @endif
                @if($inc->reported_by)
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        Reported by {{ $inc->reported_by }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- Financial Impact Cards --}}
    @if($hasFinancials)
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        @if($inc->potential_fund_loss > 0)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-amber-200 dark:border-amber-900 p-4 shadow-sm">
            <div class="flex items-center gap-2 mb-2">
                <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center">
                    <svg class="w-4 h-4 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <span class="text-xs font-medium text-amber-700 dark:text-amber-400 uppercase tracking-wider">Potential Loss</span>
            </div>
            <p class="text-lg font-bold text-amber-800 dark:text-amber-300">Rp {{ number_format($inc->potential_fund_loss, 0, ',', '.') }}</p>
        </div>
        @endif
        @if($inc->fund_loss > 0)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-red-200 dark:border-red-900 p-4 shadow-sm">
            <div class="flex items-center gap-2 mb-2">
                <div class="w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/40 flex items-center justify-center">
                    <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/></svg>
                </div>
                <span class="text-xs font-medium text-red-700 dark:text-red-400 uppercase tracking-wider">Actual Loss</span>
            </div>
            <p class="text-lg font-bold text-red-700 dark:text-red-300">Rp {{ number_format($inc->fund_loss, 0, ',', '.') }}</p>
        </div>
        @endif
        @if($inc->recovered_fund > 0)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-emerald-200 dark:border-emerald-900 p-4 shadow-sm">
            <div class="flex items-center gap-2 mb-2">
                <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center">
                    <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </div>
                <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Recovered</span>
            </div>
            <p class="text-lg font-bold text-emerald-700 dark:text-emerald-300">Rp {{ number_format($inc->recovered_fund, 0, ',', '.') }}</p>
        </div>
        @endif
    </div>
    @endif

    {{-- Two Column Layout --}}
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mb-6">

        {{-- Left Column: Content --}}
        <div class="lg:col-span-3 space-y-5">

            {{-- Summary --}}
            @if($inc->summary)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-3">
                    <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Summary
                </h3>
                <div class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap bg-gray-50 dark:bg-gray-900/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700">{{ $inc->summary }}</div>
            </div>
            @endif

            {{-- Root Cause --}}
            @if($inc->root_cause)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-3">
                    <svg class="w-4 h-4 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    Root Cause Analysis
                </h3>
                <div class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap bg-gray-50 dark:bg-gray-900/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700">{{ $inc->root_cause }}</div>
            </div>
            @endif

            {{-- Timeline --}}
            @if($inc->timeline)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-3">
                    <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Incident Timeline & Chronology
                </h3>
                <div class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap bg-gray-50 dark:bg-gray-900/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700 font-mono text-xs">{{ $inc->timeline }}</div>
            </div>
            @endif

            {{-- Remark --}}
            @if($inc->remark)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-3">
                    <svg class="w-4 h-4 text-violet-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                    Remark
                </h3>
                <div class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap bg-gray-50 dark:bg-gray-900/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700">{{ $inc->remark }}</div>
            </div>
            @endif

            {{-- Labels --}}
            @if($inc->labels && $inc->labels->isNotEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-3">
                    <svg class="w-4 h-4 text-teal-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
                    Labels
                </h3>
                <div class="flex flex-wrap gap-2">
                    @foreach($inc->labels as $label)
                        <x-filament::badge color="primary">{{ $label->name }}</x-filament::badge>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        {{-- Right Column: Meta Cards --}}
        <div class="lg:col-span-2 space-y-5">

            {{-- Triage Details --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-4">
                    <svg class="w-4 h-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Triage Details
                </h3>
                <dl class="space-y-3">
                    @php
                        $triageFields = [
                            ['label' => 'Severity', 'value' => $inc->severity, 'badge' => true, 'color' => $sevColor],
                            ['label' => 'Incident Date', 'value' => $inc->incident_date?->format('d M Y, H:i')],
                            ['label' => 'Discovered At', 'value' => $inc->discovered_at?->format('d M Y, H:i')],
                            ['label' => 'Stop Bleeding At', 'value' => $inc->stop_bleeding_at?->format('d M Y, H:i')],
                            ['label' => 'Entry Date Tech Risk', 'value' => $inc->entry_date_tech_risk?->format('d M Y')],
                        ];
                    @endphp
                    @foreach($triageFields as $field)
                        @if($field['value'])
                        <div class="flex items-center justify-between">
                            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $field['label'] }}</dt>
                            <dd>
                                @if(($field['badge'] ?? false))
                                    <x-filament::badge :color="$field['color']" size="sm">{{ $field['value'] }}</x-filament::badge>
                                @else
                                    <span class="text-sm text-gray-900 dark:text-white">{{ $field['value'] }}</span>
                                @endif
                            </dd>
                        </div>
                        @endif
                    @endforeach
                </dl>
            </div>

            {{-- Source & PIC --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-4">
                    <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    Source & PIC
                </h3>
                <dl class="space-y-3">
                    @foreach([
                        ['label' => 'Source', 'value' => $inc->incident_source],
                        ['label' => 'PIC', 'value' => $inc->pic?->name],
                        ['label' => 'Reported By', 'value' => $inc->reported_by],
                        ['label' => 'Checker', 'value' => $inc->checker],
                        ['label' => 'Maker', 'value' => $inc->maker],
                        ['label' => 'Loss Taken By', 'value' => $inc->loss_taken_by],
                    ] as $field)
                        @if($field['value'])
                        <div class="flex items-center justify-between">
                            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $field['label'] }}</dt>
                            <dd class="text-sm text-gray-900 dark:text-white">{{ $field['value'] }}</dd>
                        </div>
                        @endif
                    @endforeach
                </dl>
            </div>

            {{-- Categories --}}
            @if(!empty(array_filter([$inc->business_category, $inc->root_cause_category, $inc->responsible_team])))
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-4">
                    <svg class="w-4 h-4 text-cyan-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
                    Categories
                </h3>
                <div class="space-y-3">
                    @if($inc->business_category)
                        <div>
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Business Category</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach((array) $inc->business_category as $cat)
                                    <x-filament::badge color="info" size="sm">{{ $cat }}</x-filament::badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($inc->root_cause_category)
                        <div>
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Root Cause Category</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach((array) $inc->root_cause_category as $cat)
                                    <x-filament::badge color="warning" size="sm">{{ $cat }}</x-filament::badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($inc->responsible_team)
                        <div>
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Responsible Team</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach((array) $inc->responsible_team as $team)
                                    <x-filament::badge color="violet" size="sm">{{ $team }}</x-filament::badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- Admin Flags --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-4">
                    <svg class="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    Admin & Flags
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    @foreach([
                        ['label' => 'GoC Upload', 'value' => $inc->goc_upload],
                        ['label' => 'Teams Upload', 'value' => $inc->teams_upload],
                        ['label' => 'Doc Signed', 'value' => $inc->doc_signed],
                        ['label' => 'Risk Form CFM', 'value' => $inc->risk_incident_form_cfm],
                        ['label' => 'Glitch Flag', 'value' => $inc->glitch_flag],
                    ] as $flag)
                        <div class="flex items-center gap-2">
                            @if($flag['value'])
                                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
                            @else
                                <svg class="w-4 h-4 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M6 18L18 6M6 6l12 12"/></svg>
                            @endif
                            <span class="text-xs {{ $flag['value'] ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 dark:text-gray-500' }}">{{ $flag['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Metrics --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-4">
                    <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Metrics
                </h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-gray-50 dark:bg-gray-900/50 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">MTTR</p>
                        <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $inc->mttr ?? '-' }}</p>
                        <p class="text-xs text-gray-400">minutes</p>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-900/50 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">MTBF</p>
                        <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $inc->mtbf ?? '-' }}</p>
                        <p class="text-xs text-gray-400">days</p>
                    </div>
                </div>
            </div>

            {{-- Evidence --}}
            @if($inc->evidence || $inc->evidence_link)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-3">
                    <svg class="w-4 h-4 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                    Evidence
                </h3>
                @if($inc->evidence)
                    <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap mb-2">{{ $inc->evidence }}</p>
                @endif
                @if($inc->evidence_link)
                    <a href="{{ $inc->evidence_link }}" target="_blank" class="text-sm text-primary-600 dark:text-primary-400 hover:underline flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        View Evidence Link
                    </a>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- Relation Managers --}}
    {{ $this->relationManagerContent() }}
</div>
