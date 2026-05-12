<?php

namespace App\Services\Analytics;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Filament\Concerns\HasChartColors;
use App\Models\Incident;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsQueryService
{
    use HasChartColors;

    public const METRICS = [
        'count' => 'Incident Count',
        'avg_mttr' => 'Avg MTTR (minutes)',
        'avg_mttr_days' => 'Avg MTTR (days)',
        'avg_mtbf' => 'Avg MTBF (days)',
        'sum_fund_loss' => 'Total Fund Loss',
        'sum_potential_fund_loss' => 'Total Potential Loss',
        'sum_recovered_fund' => 'Total Recovered',
        'recovery_rate' => 'Recovery Rate (%)',
    ];

    public const DIMENSIONS = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'severity' => 'Severity',
        'incident_type' => 'Incident Type',
        'status' => 'Status',
        'pic' => 'Person In Charge',
        'label' => 'Label',
        'business_category' => 'Business Category',
        'root_cause_category' => 'Root Cause Category',
        'responsible_team' => 'Responsible Team',
        'fund_status' => 'Fund Status',
    ];

    public const CHART_TYPES = [
        'bar' => 'Vertical Bar',
        'line' => 'Line',
        'pie' => 'Pie / Doughnut',
        'horizontal_bar' => 'Horizontal Bar',
    ];

    public function build(string $metric, string $dimension, string $chartType, array $filters = [], ?array $comparison = null): array
    {
        $cacheKey = 'analytics_'.md5(json_encode(compact('metric', 'dimension', 'filters')));

        $primary = Cache::remember($cacheKey, now()->addMinutes(15), fn () => $this->buildSingleDataset($metric, $dimension, $filters));

        $dataset = $this->applyChartStyle($primary, $chartType, self::chartColors(1)[0], self::chartBorderColors(1)[0]);
        $dataset['label'] = self::METRICS[$metric] ?? $metric;

        $result = [
            'datasets' => [$dataset],
            'labels' => $primary['labels'],
            'raw_data' => [$primary['raw_data']],
        ];

        if ($comparison && ($comparison['enabled'] ?? false)) {
            $compFilters = $this->deriveComparisonFilters($filters, $comparison);
            $compCacheKey = 'analytics_'.md5(json_encode(['metric' => $metric, 'dimension' => $dimension, 'filters' => $compFilters]));
            $secondary = Cache::remember($compCacheKey, now()->addMinutes(15), fn () => $this->buildSingleDataset($metric, $dimension, $compFilters));

            $secondaryAligned = $this->alignToLabels($secondary, $primary['labels']);
            $compDataset = $this->applyChartStyle($secondaryAligned, $chartType, self::chartColors(2)[1], self::chartBorderColors(2)[1]);
            $compDataset['label'] = $comparison['secondary_label'] ?? 'Comparison Period';

            $result['datasets'][] = $compDataset;
            $result['raw_data'][] = $secondaryAligned['raw_data'];
        }

        return $result;
    }

    public function buildSingleDataset(string $metric, string $dimension, array $filters): array
    {
        $query = $this->baseQuery($filters);

        return match ($dimension) {
            'monthly', 'quarterly' => $this->queryTimeDimension($query, $metric, $dimension, $filters),
            'severity', 'incident_type', 'status', 'fund_status' => $this->queryEnumDimension($query, $metric, $dimension),
            'pic' => $this->queryRelationDimension($query, $metric),
            'label' => $this->queryPivotDimension($query, $metric),
            'business_category', 'root_cause_category', 'responsible_team' => $this->queryJsonArrayDimension($query, $metric, $dimension),
            default => ['labels' => [], 'values' => [], 'raw_data' => []],
        };
    }

    private function baseQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = Incident::query()
            ->where('classification', '!=', 'Issue');

        if (! empty($filters['start_date'])) {
            $query->where('incident_date', '>=', Carbon::parse($filters['start_date'])->startOfDay());
        }
        if (! empty($filters['end_date'])) {
            $query->where('incident_date', '<=', Carbon::parse($filters['end_date'])->endOfDay());
        }
        if (! empty($filters['severities'])) {
            $query->whereIn('severity', $filters['severities']);
        }
        if (! empty($filters['incident_types'])) {
            $query->whereIn('incident_type', $filters['incident_types']);
        }
        if (! empty($filters['statuses'])) {
            $query->whereIn('incident_status', $filters['statuses']);
        }
        if (! empty($filters['fund_statuses'])) {
            $query->whereIn('fund_status', $filters['fund_statuses']);
        }

        return $query;
    }

    private function getAggregateExpression(string $metric): ?string
    {
        return match ($metric) {
            'count' => 'COUNT(*)',
            'avg_mttr' => 'AVG(CASE WHEN mttr >= 0 THEN mttr ELSE NULL END)',
            'avg_mttr_days' => 'AVG(CASE WHEN mttr < 0 THEN ABS(mttr) ELSE NULL END)',
            'sum_fund_loss' => 'COALESCE(SUM(fund_loss), 0)',
            'sum_potential_fund_loss' => 'COALESCE(SUM(potential_fund_loss), 0)',
            'sum_recovered_fund' => 'COALESCE(SUM(recovered_fund), 0)',
            'recovery_rate' => 'CASE WHEN SUM(potential_fund_loss) > 0 THEN ROUND(SUM(recovered_fund) / SUM(potential_fund_loss) * 100, 2) ELSE 0 END',
            default => null,
        };
    }

    private function isDerivedMetric(string $metric): bool
    {
        return $metric === 'avg_mtbf';
    }

    private function queryTimeDimension(\Illuminate\Database\Eloquent\Builder $query, string $metric, string $dimension, array $filters): array
    {
        $dimSql = $dimension === 'quarterly'
            ? "CONCAT(YEAR(incident_date), '-Q', QUARTER(incident_date))"
            : "DATE_FORMAT(incident_date, '%Y-%m')";

        if ($this->isDerivedMetric($metric)) {
            $rows = $query->whereIn('severity', Severity::METRIC_ELIGIBLE)
                ->selectRaw("{$dimSql} as dim, MIN(incident_date) as min_date, MAX(incident_date) as max_date, COUNT(*) as cnt")
                ->groupByRaw($dimSql)
                ->orderBy('dim')
                ->get();

            $data = [];
            foreach ($rows as $row) {
                if ($row->cnt > 1 && $row->min_date && $row->max_date) {
                    $data[$row->dim] = round(
                        Carbon::parse($row->min_date)->startOfDay()->diffInDays(Carbon::parse($row->max_date)->startOfDay()) / ($row->cnt - 1),
                        2
                    );
                } else {
                    $data[$row->dim] = 0;
                }
            }

            $labels = $this->fillTimeLabels($data, $dimension, $filters);
            $values = array_map(fn ($l) => $data[$l] ?? 0, $labels);
        } else {
            $agg = $this->getAggregateExpression($metric);
            $rows = $query->selectRaw("{$dimSql} as dim, {$agg} as value")
                ->groupByRaw($dimSql)
                ->orderBy('dim')
                ->pluck('value', 'dim')
                ->toArray();

            $labels = $this->fillTimeLabels($rows, $dimension, $filters);
            $values = array_map(fn ($l) => round((float) ($rows[$l] ?? 0), 2), $labels);
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'raw_data' => collect($labels)->map(fn ($label, $i) => ['label' => $label, 'value' => $values[$i]])->values()->toArray(),
        ];
    }

    private function queryEnumDimension(\Illuminate\Database\Eloquent\Builder $query, string $metric, string $dimension): array
    {
        $column = match ($dimension) {
            'severity' => 'severity',
            'incident_type' => 'incident_type',
            'status' => 'incident_status',
            'fund_status' => 'fund_status',
        };

        if ($this->isDerivedMetric($metric)) {
            $rows = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)
                ->selectRaw("{$column} as dim, MIN(incident_date) as min_date, MAX(incident_date) as max_date, COUNT(*) as cnt")
                ->groupBy($column)
                ->get();

            $data = [];
            foreach ($rows as $row) {
                if ($row->cnt > 1 && $row->min_date && $row->max_date) {
                    $data[$row->dim] = round(
                        Carbon::parse($row->min_date)->startOfDay()->diffInDays(Carbon::parse($row->max_date)->startOfDay()) / ($row->cnt - 1),
                        2
                    );
                } else {
                    $data[$row->dim] = 0;
                }
            }

            $labels = array_keys($data);
            $values = array_values($data);
        } else {
            $agg = $this->getAggregateExpression($metric);
            $rows = $query->selectRaw("{$column} as dim, {$agg} as value")
                ->groupBy($column)
                ->pluck('value', 'dim')
                ->toArray();

            if ($dimension === 'severity') {
                $order = Severity::fieldOrderExpression('dim');
                uksort($rows, fn ($a, $b) => (array_search($a, array_column(Severity::cases(), 'value')) ?? 99) - (array_search($b, array_column(Severity::cases(), 'value')) ?? 99));
            } elseif ($dimension === 'status') {
                uksort($rows, fn ($a, $b) => (array_search($a, array_column(IncidentStatus::cases(), 'value')) ?? 99) - (array_search($b, array_column(IncidentStatus::cases(), 'value')) ?? 99));
            }

            $labels = array_keys($rows);
            $values = array_map(fn ($v) => round((float) $v, 2), array_values($rows));
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'raw_data' => collect($labels)->map(fn ($label, $i) => ['label' => $label, 'value' => $values[$i]])->values()->toArray(),
        ];
    }

    private function queryRelationDimension(\Illuminate\Database\Eloquent\Builder $query, string $metric): array
    {
        if ($this->isDerivedMetric($metric)) {
            $rows = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)
                ->join('users', 'incidents.pic_id', '=', 'users.id')
                ->selectRaw('users.name as dim, MIN(incident_date) as min_date, MAX(incident_date) as max_date, COUNT(*) as cnt')
                ->groupBy('users.name')
                ->orderByDesc('cnt')
                ->get();

            $data = [];
            foreach ($rows as $row) {
                if ($row->cnt > 1 && $row->min_date && $row->max_date) {
                    $data[$row->dim] = round(
                        Carbon::parse($row->min_date)->startOfDay()->diffInDays(Carbon::parse($row->max_date)->startOfDay()) / ($row->cnt - 1),
                        2
                    );
                } else {
                    $data[$row->dim] = 0;
                }
            }

            $labels = array_keys($data);
            $values = array_values($data);
        } else {
            $agg = $this->getAggregateExpression($metric);
            $rows = $query->join('users', 'incidents.pic_id', '=', 'users.id')
                ->selectRaw("users.name as dim, {$agg} as value")
                ->groupBy('users.name')
                ->orderByDesc('value')
                ->pluck('value', 'dim')
                ->toArray();

            $labels = array_keys($rows);
            $values = array_map(fn ($v) => round((float) $v, 2), array_values($rows));
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'raw_data' => collect($labels)->map(fn ($label, $i) => ['label' => $label, 'value' => $values[$i]])->values()->toArray(),
        ];
    }

    private function queryPivotDimension(\Illuminate\Database\Eloquent\Builder $query, string $metric): array
    {
        if ($this->isDerivedMetric($metric)) {
            $cloned = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE);
            $cloned->join('incident_label', 'incidents.id', '=', 'incident_label.incident_id')
                ->join('labels', 'incident_label.label_id', '=', 'labels.id')
                ->selectRaw('labels.name as dim, MIN(incident_date) as min_date, MAX(incident_date) as max_date, COUNT(*) as cnt')
                ->groupBy('labels.name')
                ->orderByDesc('cnt');

            $rows = $cloned->get();
            $data = [];
            foreach ($rows as $row) {
                if ($row->cnt > 1 && $row->min_date && $row->max_date) {
                    $data[$row->dim] = round(
                        Carbon::parse($row->min_date)->startOfDay()->diffInDays(Carbon::parse($row->max_date)->startOfDay()) / ($row->cnt - 1),
                        2
                    );
                } else {
                    $data[$row->dim] = 0;
                }
            }

            $labels = array_keys($data);
            $values = array_values($data);
        } else {
            $agg = $this->getAggregateExpression($metric);
            $rows = $query->join('incident_label', 'incidents.id', '=', 'incident_label.incident_id')
                ->join('labels', 'incident_label.label_id', '=', 'labels.id')
                ->selectRaw("labels.name as dim, {$agg} as value")
                ->groupBy('labels.name')
                ->orderByDesc('value')
                ->pluck('value', 'dim')
                ->toArray();

            $labels = array_keys($rows);
            $values = array_map(fn ($v) => round((float) $v, 2), array_values($rows));
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'raw_data' => collect($labels)->map(fn ($label, $i) => ['label' => $label, 'value' => $values[$i]])->values()->toArray(),
        ];
    }

    private function queryJsonArrayDimension(\Illuminate\Database\Eloquent\Builder $query, string $metric, string $dimension): array
    {
        $column = $dimension;
        $rows = $query->get([$column, 'mttr', 'fund_loss', 'potential_fund_loss', 'recovered_fund', 'incident_date']);

        $grouped = [];
        foreach ($rows as $incident) {
            $values = $incident->$column ?? [];
            if (empty($values) || ! is_array($values)) {
                continue;
            }

            foreach ($values as $val) {
                if (! isset($grouped[$val])) {
                    $grouped[$val] = [
                        'count' => 0,
                        'mttr_sum' => 0,
                        'mttr_days_sum' => 0,
                        'fund_loss_sum' => 0,
                        'potential_sum' => 0,
                        'recovered_sum' => 0,
                        'mtbf_dates' => [],
                    ];
                }

                $grouped[$val]['count']++;

                if ($incident->mttr !== null) {
                    if ($incident->mttr >= 0) {
                        $grouped[$val]['mttr_sum'] += $incident->mttr;
                    } else {
                        $grouped[$val]['mttr_days_sum'] += abs($incident->mttr);
                    }
                }

                $grouped[$val]['fund_loss_sum'] += (float) ($incident->fund_loss ?? 0);
                $grouped[$val]['potential_sum'] += (float) ($incident->potential_fund_loss ?? 0);
                $grouped[$val]['recovered_sum'] += (float) ($incident->recovered_fund ?? 0);

                if ($incident->incident_date && in_array($incident->severity ?? '', Severity::METRIC_ELIGIBLE)) {
                    $grouped[$val]['mtbf_dates'][] = Carbon::parse($incident->incident_date)->startOfDay();
                }
            }
        }

        $result = [];
        foreach ($grouped as $label => $stats) {
            $result[$label] = match ($metric) {
                'count' => $stats['count'],
                'avg_mttr' => $stats['count'] > 0 ? round($stats['mttr_sum'] / $stats['count'], 2) : 0,
                'avg_mttr_days' => $stats['count'] > 0 ? round($stats['mttr_days_sum'] / $stats['count'], 2) : 0,
                'avg_mtbf' => $this->computeMtbf($stats['mtbf_dates']),
                'sum_fund_loss' => round($stats['fund_loss_sum'], 2),
                'sum_potential_fund_loss' => round($stats['potential_sum'], 2),
                'sum_recovered_fund' => round($stats['recovered_sum'], 2),
                'recovery_rate' => $stats['potential_sum'] > 0
                    ? round($stats['recovered_sum'] / $stats['potential_sum'] * 100, 2)
                    : 0,
                default => 0,
            };
        }

        arsort($result);

        return [
            'labels' => array_keys($result),
            'values' => array_values($result),
            'raw_data' => collect($result)->map(fn ($value, $label) => ['label' => $label, 'value' => $value])->values()->toArray(),
        ];
    }

    private function computeMtbf(array $dates): float
    {
        $dates = collect($dates)->unique()->sort()->values();
        if ($dates->count() < 2) {
            return 0;
        }

        return round($dates->first()->diffInDays($dates->last()) / ($dates->count() - 1), 2);
    }

    private function fillTimeLabels(array $data, string $dimension, array $filters): array
    {
        $start = ! empty($filters['start_date'])
            ? Carbon::parse($filters['start_date'])
            : Carbon::now()->startOfYear();
        $end = ! empty($filters['end_date'])
            ? Carbon::parse($filters['end_date'])
            : Carbon::now()->endOfYear();

        $labels = [];
        $current = $start->copy();

        if ($dimension === 'monthly') {
            while ($current->lte($end)) {
                $labels[] = $current->format('Y-m');
                $current->addMonth();
            }
        } else {
            while ($current->lte($end)) {
                $labels[] = $current->format('Y').'-Q'.$current->quarter;
                $current->addQuarter();
            }
        }

        return $labels;
    }

    private function deriveComparisonFilters(array $filters, array $comparison): array
    {
        $compFilters = $filters;

        if (($comparison['mode'] ?? 'previous_period') === 'previous_period') {
            $start = ! empty($filters['start_date']) ? Carbon::parse($filters['start_date']) : Carbon::now()->startOfYear();
            $end = ! empty($filters['end_date']) ? Carbon::parse($filters['end_date']) : Carbon::now()->endOfYear();
            $duration = $start->diffInDays($end);

            $compFilters['start_date'] = $start->copy()->subDays($duration + 1)->toDateString();
            $compFilters['end_date'] = $start->copy()->subDay()->toDateString();
        } else {
            $compFilters['start_date'] = $comparison['start_date'] ?? $filters['start_date'];
            $compFilters['end_date'] = $comparison['end_date'] ?? $filters['end_date'];
        }

        return $compFilters;
    }

    private function alignToLabels(array $dataset, array $targetLabels): array
    {
        $values = [];
        $rawData = [];
        $labelMap = array_combine($dataset['labels'], $dataset['values']);

        foreach ($targetLabels as $label) {
            $val = $labelMap[$label] ?? 0;
            $values[] = $val;
            $rawData[] = ['label' => $label, 'value' => $val];
        }

        return [
            'labels' => $targetLabels,
            'values' => $values,
            'raw_data' => $rawData,
        ];
    }

    private function applyChartStyle(array $dataset, string $chartType, string $bgColor, string $borderColor): array
    {
        $isPie = $chartType === 'pie';
        $count = count($dataset['values'] ?? []);

        $style = [
            'data' => $dataset['values'] ?? [],
        ];

        if ($isPie) {
            $style['backgroundColor'] = self::chartColors(max($count, 1));
            $style['borderColor'] = self::chartBorderColors(max($count, 1));
            $style['borderWidth'] = 2;
        } elseif ($chartType === 'line') {
            $style['backgroundColor'] = str_replace('0.8', '0.12', $bgColor);
            $style['borderColor'] = $borderColor;
            $style['fill'] = true;
            $style['tension'] = 0.4;
            $style['pointBackgroundColor'] = $borderColor;
            $style['pointBorderColor'] = '#fff';
            $style['pointBorderWidth'] = 2;
            $style['pointRadius'] = 4;
            $style['pointHoverRadius'] = 6;
            $style['borderWidth'] = 2;
        } else {
            $style['backgroundColor'] = $bgColor;
            $style['borderColor'] = $borderColor;
            $style['borderWidth'] = 2;
            $style['borderRadius'] = 6;
        }

        return $style;
    }

    public static function metricLabel(string $metric): string
    {
        return self::METRICS[$metric] ?? $metric;
    }

    public static function dimensionLabel(string $dimension): string
    {
        return self::DIMENSIONS[$dimension] ?? ucfirst(str_replace('_', ' ', $dimension));
    }
}
