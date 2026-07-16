@php
    use App\Enums\FundStatus;
    use App\Enums\IncidentStatus;
    use App\Enums\Severity;

    $inc = $entry->getRecord();
    $sevColor = $inc->severity?->color() ?? 'gray';
    $statusColor = $inc->incident_status?->color() ?? 'gray';
    $fundColor = $inc->fund_status?->color() ?? 'gray';

    $sevBg = match($sevColor) {
        'danger' => 'bg-red-600',
        'warning' => 'bg-amber-600',
        'info' => 'bg-blue-600',
        'success' => 'bg-emerald-600',
        default => 'bg-slate-600',
    };

    $hasFinancials = $inc->potential_fund_loss > 0 || $inc->fund_loss > 0 || $inc->recovered_fund > 0;
    $inc->loadMissing(['pic', 'labels', 'incidentType', 'latestStatusUpdate']);
@endphp

{{-- Hero Header --}}
<div class="rounded-xl overflow-hidden mb-6 {{ $sevBg }} shadow-lg">
    <div class="px-6 py-5">
        <div class="flex flex-wrap items-center gap-2 mb-3">
            <span class="text-white/70 text-xs font-mono tracking-wider">{{ $inc->no }}</span>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-white/20 text-white">{{ $inc->severity }}</span>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-white/20 text-white">{{ $inc->incident_status }}</span>
            @if($inc->fund_status)
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-white/20 text-white">{{ $inc->fund_status }}</span>
            @endif
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-white/10 text-white/80">{{ $inc->incident_type }}</span>
            @if($inc->classification)
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-white/10 text-white/80">{{ $inc->classification }}</span>
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
