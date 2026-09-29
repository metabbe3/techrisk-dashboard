<?php

namespace App\Exports;

use App\Enums\Severity;
use App\Filament\Statistics\IncidentStatsFooterData;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Grouped export: one sheet per group value for the chosen dimension
 * (division/responsible_team, business_category, root_cause_category, PIC),
 * plus a per-group MTTR/MTBF summary sheet at the front.
 *
 * One incident with multiple group values appears in each matching sheet.
 */
class GroupedIncidentsExport implements WithMultipleSheets
{
    public const DIMENSIONS = [
        'business_category' => ['label' => 'Business Category', 'json' => true],
        'root_cause_category' => ['label' => 'Root Cause Category', 'json' => true],
        'responsible_team' => ['label' => 'Division / Responsible Team', 'json' => true],
        'pic' => ['label' => 'PIC', 'json' => false, 'column' => 'pic_id', 'nameFrom' => 'pic.name'],
        'severity' => ['label' => 'Severity', 'json' => false],
        'incident_type' => ['label' => 'Incident Type', 'json' => false],
    ];

    protected Builder $query;

    protected string $dimension;

    public function __construct(Builder $query, string $dimension)
    {
        $this->query = $query->clone();
        $this->dimension = $dimension;
    }

    public function sheets(): array
    {
        $config = self::DIMENSIONS[$this->dimension] ?? null;
        abort_unless($config, 422, "Unknown grouping dimension: {$this->dimension}");

        $isJson = $config['json'];
        $column = $config['column'] ?? $this->dimension;

        $rows = $this->query->get();

        // distinct group values
        if ($isJson) {
            $values = $rows->pluck($this->dimension)->filter()->flatMap(fn ($a) => (array) $a)->unique()->sort()->values();
        } elseif ($this->dimension === 'pic') {
            $values = $rows->filter(fn ($i) => $i->pic_id)->pluck('pic_id')->unique()->sort();
            $names = \App\Models\User::whereIn('id', $values)->pluck('name', 'id');
        } else {
            $values = $rows->pluck($this->dimension)->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->filter()->unique()->sort()->values();
        }

        $sheets = [];

        // Summary sheet first: per-group counts + MTTR/MTBF (the numbers owner cares about)
        $groupStats = [];
        foreach ($values as $value) {
            $groupRows = $rows->filter(function ($i) use ($isJson, $value): bool {
                if ($isJson) {
                    return in_array($value, (array) $i->{$this->dimension});
                }
                $v = $i->{$column};
                $v = $v instanceof \BackedEnum ? $v->value : $v;

                return $v == $value;
            });
            if ($groupRows->isEmpty()) {
                continue;
            }
            $eligible = $groupRows->filter(fn ($i) => in_array($i->severity?->value ?? $i->severity, Severity::METRIC_ELIGIBLE));
            // avg MTBF per group: (max-min date among eligible) / (count-1)
            $eligibleDates = $eligible->pluck('incident_date')->filter()->sort()->values();
            $avgMtbf = 0;
            if ($eligibleDates->count() > 1) {
                $spanDays = $eligibleDates->first()->startOfDay()->diffInDays($eligibleDates->last()->startOfDay());
                $avgMtbf = round($spanDays / ($eligibleDates->count() - 1), 1);
            }
            $groupStats[] = [
                'label' => $this->dimension === 'pic' ? ($names[$value] ?? "PIC {$value}") : (string) $value,
                'count' => $groupRows->count(),
                'avgMttrMins' => round($eligible->where('mttr', '>=', 0)->avg('mttr') ?? 0, 1),
                'avgMttrDays' => round(abs($eligible->where('mttr', '<', 0)->avg('mttr') ?? 0), 1),
                'avgMtbf' => $avgMtbf,
                'mttrDataCount' => $eligible->whereNotNull('mttr')->count(),
                'potential' => (float) $groupRows->sum('potential_fund_loss'),
                'actual' => (float) $groupRows->sum('fund_loss'),
                'recovered' => (float) $groupRows->sum('recovered_fund'),
            ];
        }

        $sheets[] = new GroupSummarySheet($groupStats, $config['label']);

        foreach ($groupStats as $gs) {
            $groupValue = $this->dimension === 'pic'
                ? $gs['label']
                : $gs['label'];
            // For PIC sheets the filter must use the id; pass raw value through closure
            $sheets[] = new PerCategorySheet(
                $this->query,
                $this->dimension === 'pic' ? 'pic_id' : ($config['column'] ?? $this->dimension),
                $isJson,
                $this->dimension === 'pic' ? array_search($gs['label'], $names->all(), true) : $gs['label']
            );
        }

        return $sheets;
    }
}

/**
 * Front summary: one row per group with counts and MTTR/MTBF aggregates.
 */
class GroupSummarySheet implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\ShouldAutoSize, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithMapping, \Maatwebsite\Excel\Concerns\WithTitle
{
    protected array $groupStats;

    protected string $label;

    public function __construct(array $groupStats, string $label)
    {
        $this->groupStats = $groupStats;
        $this->label = $label;
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function headings(): array
    {
        return [$this->label, 'Cases', 'Avg MTTR (min)', 'Avg MTTR (days)', 'Avg MTBF (days)', 'Cases w/ MTTR', 'Potential Loss', 'Actual Loss', 'Recovered'];
    }

    public function collection()
    {
        return collect($this->groupStats);
    }

    public function map($row): array
    {
        return [
            $row['label'],
            $row['count'],
            $row['avgMttrMins'],
            $row['avgMttrDays'],
            $row['avgMtbf'],
            $row['mttrDataCount'],
            $row['potential'],
            $row['actual'],
            $row['recovered'],
        ];
    }
}
