<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\IncidentStatus;
use App\Filament\Concerns\HasChartColors;
use App\Models\Incident;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

/**
 * Root-cause Pareto weighted by fund loss (VP TechOps PDR): which
 * categories carry the Rp, with a cumulative-% line. Widget-aligned fund
 * scope (same as the Fund Loss card): aiCounts() + Completed. A row with
 * multiple categories splits its loss evenly so the bars sum to the total.
 */
class RootCauseFundParetoChart extends ChartWidget
{
    use HasChartColors;

    protected static ?string $heading = 'Root Cause vs Fund Loss (Pareto)';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $cacheKey = 'rca_fund_pareto_v1_'.md5(json_encode([
            'start' => $this->start_date,
            'end' => $this->end_date,
            'v' => (int) Cache::get('dashboard_cache_version', 0),
        ]));

        $pareto = Cache::remember($cacheKey, now()->addMinutes(15), fn () => $this->pareto());

        // ponytail: start_date/end_date are declared but never wired (no
        // dashboard-filter trait on this widget) — the chart is fixed to the
        // current year and the dates in the cache key are constant null.
        // Wire InteractsWithDashboardFilters + scope pareto() if/when the
        // chart should follow the dashboard date filters (scope would then
        // move — owner-visible numbers).

        return [
            'datasets' => [
                [
                    'label' => 'Actual Fund Loss',
                    'data' => array_column($pareto, 'loss'),
                    'backgroundColor' => self::chartColors(1)[0],
                    'borderRadius' => 4,
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Cumulative %',
                    'type' => 'line',
                    'data' => array_column($pareto, 'cumulative_pct'),
                    'borderColor' => self::chartColors(2)[1],
                    'backgroundColor' => self::chartColors(2)[1],
                    'tension' => 0.3,
                    'pointRadius' => 3,
                    'yAxisID' => 'y1',
                ],
            ],
            'labels' => array_column($pareto, 'category'),
        ];
    }

    /**
     * @return list<array{category: string, loss: float, cumulative_pct: float}>
     */
    private function pareto(): array
    {
        $rows = Incident::query()
            ->aiCounts()
            ->where('incident_status', IncidentStatus::Completed->value)
            ->whereYear('incident_date', now()->year)
            ->get(['fund_loss', 'root_cause_category']);

        $perCategory = [];
        foreach ($rows as $row) {
            $categories = array_filter($row->root_cause_category ?: []);
            $share = (float) $row->fund_loss / max(count($categories), 1);
            // Uncategorised loss stays visible instead of vanishing.
            $categories = $categories ?: ['(uncategorized)'];
            foreach ($categories as $category) {
                $perCategory[$category] = ($perCategory[$category] ?? 0) + $share;
            }
        }

        $total = array_sum($perCategory);
        if ($total <= 0) {
            return [];
        }

        arsort($perCategory);

        $rowsOut = [];
        $running = 0.0;
        foreach (array_slice($perCategory, 0, 8, true) as $category => $loss) {
            $running += $loss;
            $rowsOut[] = [
                'category' => (string) $category,
                'loss' => round($loss, 2),
                'cumulative_pct' => round($running / $total * 100, 1),
            ];
        }

        return $rowsOut;
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'position' => 'left',
                    'title' => ['display' => true, 'text' => 'Rp'],
                ],
                'y1' => [
                    'beginAtZero' => true,
                    'max' => 100,
                    'position' => 'right',
                    'title' => ['display' => true, 'text' => '%'],
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
