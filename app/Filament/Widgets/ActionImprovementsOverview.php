<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\ActionImprovement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class ActionImprovementsOverview extends BaseWidget
{
    use InteractsWithDashboardFilters;

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 8,
    ];

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        // Generate dynamic cache key based on filters
        $cacheKey = 'action_improvements_v2_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
            'v' => Cache::get('dashboard_cache_version', 0),
        ]));

        return Cache::remember($cacheKey, now()->addMinutes(5), function () {
            // Fresh builder per stat — the status-count query groups by
            // status, and a clone of it drags that GROUP BY into the
            // aging/closeout queries (per-group rows, not table-wide).
            $scoped = function (): \Illuminate\Database\Eloquent\Builder {
                $query = ActionImprovement::query();

                if ($this->start_date && $this->end_date) {
                    $query->whereBetween('created_at', [$this->start_date, $this->end_date]);
                } else {
                    $query->whereYear('created_at', now()->year);
                }

                return $query;
            };

            $descriptionPeriod = ($this->start_date && $this->end_date) ? 'in the selected period' : 'this year';

            // Optimized: Use single GROUP BY query instead of two separate count queries
            $results = $scoped()->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            $pendingCount = ($results[ActionImprovement::STATUS_PENDING] ?? 0)
                + ($results[ActionImprovement::STATUS_ON_PROGRESS] ?? 0);
            $doneCount = $results[ActionImprovement::STATUS_DONE] ?? 0;

            // PROJ-014: overdue aging aligned with the escalation ladder —
            // same "open work + past due" semantics as governance
            // overdueActionItemCount(), plus escalated rows separately.
            // Open work = pending + on_progress (owner 2026-11-17, Rule A).
            $aging = $scoped()
                ->selectRaw('
                    COUNT(CASE WHEN status IN (?, ?) AND due_date < ? THEN 1 END) as overdue,
                    COUNT(CASE WHEN status IN (?, ?) AND due_date < ? AND escalation_level > 0 THEN 1 END) as escalated,
                    COUNT(CASE WHEN status IN (?, ?) AND due_date IS NULL THEN 1 END) as tbd
                ', [
                    ActionImprovement::STATUS_PENDING, ActionImprovement::STATUS_ON_PROGRESS, today()->toDateString(),
                    ActionImprovement::STATUS_PENDING, ActionImprovement::STATUS_ON_PROGRESS, today()->toDateString(),
                    ActionImprovement::STATUS_PENDING, ActionImprovement::STATUS_ON_PROGRESS,
                ])
                ->first();
            $overdueCount = (int) ($aging->overdue ?? 0);
            $escalatedCount = (int) ($aging->escalated ?? 0);
            $tbdCount = (int) ($aging->tbd ?? 0);

            // Avg days-to-close over done rows (created_at → completed_at);
            // '-' until closeout data exists (pre-PROJ-14 done rows have none).
            // Computed in PHP — a SQL datediff would need per-driver SQL
            // (SQLite julianday vs MySQL TIMESTAMPDIFF) for a small row set.
            $closed = $scoped()
                ->whereNotNull('completed_at')
                ->get(['created_at', 'completed_at']);
            $avgDays = $closed->isNotEmpty()
                ? $closed->average(fn ($row) => $row->created_at->diffInDays($row->completed_at))
                : null;

            return [
                Stat::make('Pending Action Improvements', $pendingCount)
                    // TBD riders on the pending card (owner 2026-10-14): rows
                    // with no due date never fire reminders — the count is the
                    // only thing that surfaces them ("escalated" precedent).
                    ->description('Pending actions '.$descriptionPeriod.($tbdCount > 0 ? " · {$tbdCount} TBD (no due date)" : ''))
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->chart([$doneCount, $pendingCount, max(0, $pendingCount - 1), $pendingCount + 1, $pendingCount])
                    ->color('warning'),
                Stat::make('Overdue Action Improvements', $overdueCount)
                    ->description($escalatedCount > 0
                        ? $escalatedCount.' escalated to a manager'
                        : 'Pending past their due date')
                    ->descriptionIcon('heroicon-m-clock')
                    ->color($overdueCount > 0 ? 'danger' : 'success'),
                Stat::make('Done Action Improvements', $doneCount)
                    ->description('Done actions '.$descriptionPeriod)
                    ->descriptionIcon('heroicon-m-check-circle')
                    ->chart([$pendingCount, $doneCount, max(0, $doneCount - 1), $doneCount + 1, $doneCount])
                    ->color('success'),
                Stat::make('Avg Days to Close', $avgDays !== null ? round((float) $avgDays, 1) : '—')
                    ->description('Created → completed (done rows)')
                    ->descriptionIcon('heroicon-m-calendar-days')
                    ->color('info'),
            ];
        });
    }
}
