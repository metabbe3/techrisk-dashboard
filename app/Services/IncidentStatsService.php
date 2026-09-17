<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Services\Markdown\MarkdownFormatter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class IncidentStatsService
{
    public function getBaseStats(Carbon $from, Carbon $to): array
    {
        // aiCounts(): classification Incident + severity G/Non Incident excluded
        // + fund-status excluded — the one counting rule shared with the
        // dashboard cards and every AI-facing count.
        $total = Incident::aiCounts()
            ->whereBetween('incident_date', [$from, $to])
            ->count();

        $open = Incident::aiCounts()
            ->whereBetween('incident_date', [$from, $to])
            ->whereNotIn('incident_status', ['Completed'])
            ->count();

        $fundLoss = Incident::where('classification', IncidentClassification::Incident->value)
            ->whereBetween('incident_date', [$from, $to])
            ->excludedFromCounts()
            ->sum('fund_loss');

        $bySeverity = Incident::aiCounts()
            ->whereBetween('incident_date', [$from, $to])
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        return [
            'total' => $total,
            'open' => $open,
            'fund_loss' => (float) $fundLoss,
            'by_severity' => $bySeverity,
            'from' => $from,
            'to' => $to,
        ];
    }

    public function formatCompact(array $stats): string
    {
        $severityBreakdown = collect($stats['by_severity'])->map(fn ($count, $sev) => "{$sev}: {$count}")->implode(', ');

        return "Period: {$stats['from']->format('Y-m-d')} to {$stats['to']->format('Y-m-d')}\n"
            ."Total Incidents: {$stats['total']} | Open: {$stats['open']}\n"
            .'Total Fund Loss: '.MarkdownFormatter::formatMoney($stats['fund_loss'])."\n"
            ."By Severity: {$severityBreakdown}";
    }

    public function getCachedStats(string $period): string
    {
        return match ($period) {
            'this_month' => Cache::remember('warroom_stats_month', 300, fn () => $this->formatCompact($this->getBaseStats(now()->startOfMonth(), now()))),
            'this_quarter' => Cache::remember('warroom_stats_quarter', 300, fn () => $this->formatCompact($this->getBaseStats(now()->startOfQuarter(), now()))),
            default => Cache::remember('warroom_stats_year', 300, fn () => $this->formatCompact($this->getBaseStats(now()->startOfYear(), now()))),
        };
    }
}
