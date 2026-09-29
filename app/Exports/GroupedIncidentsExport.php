<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\Severity;
use App\Exports\Sheets\GroupSummarySheet;
use App\Exports\Sheets\PerCategorySheet;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

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
        } elseif ($this->dimension === 'severity') {
            // Owner rule (2026-09-29): severity grouping is metric-eligible only
            // (P1–P4, X1–X4) — G and Non Incident never get sheets. The empty-group
            // guard below skips severities with no rows, so order stays enum order.
            $values = collect(Severity::METRIC_ELIGIBLE);
        } else {
            $values = $rows->pluck($this->dimension)->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->filter()->unique()->sort()->values();
        }

        $sheets = [];

        // Summary sheet first: per-group counts + MTTR/MTBF (the numbers owner cares about)
        $groupStats = [];
        foreach ($values as $value) {
            // BUG-022: $column MUST be imported — reading it undefined threw a
            // warning per row (ErrorException = HTTP 500 on the web path) and
            // emptied every non-JSON group (severity / pic / incident_type).
            $groupRows = $rows->filter(function ($i) use ($isJson, $value, $column): bool {
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
                'value' => $value,
                'count' => $groupRows->count(),
                // BUG-021: cast aggregates for round()/abs() under strict_types — uniform
                // pattern even though Collection::avg() returns float (MySQL rule).
                'avgMttrMins' => round((float) ($eligible->where('mttr', '>=', 0)->avg('mttr') ?? 0), 1),
                'avgMttrDays' => round(abs((float) ($eligible->where('mttr', '<', 0)->avg('mttr') ?? 0)), 1),
                'avgMtbf' => $avgMtbf,
                'mttrDataCount' => $eligible->whereNotNull('mttr')->count(),
                'potential' => (float) $groupRows->sum('potential_fund_loss'),
                'actual' => (float) $groupRows->sum('fund_loss'),
                'recovered' => (float) $groupRows->sum('recovered_fund'),
            ];
        }

        $sheets[] = new GroupSummarySheet($groupStats, $config['label']);

        foreach ($groupStats as $gs) {
            // Filter by the RAW group value (pic = user id, severity = enum
            // value); the label only names the sheet. Resolving pic by label
            // (array_search over names) collapsed same-name PICs onto one id.
            $sheets[] = new PerCategorySheet(
                $this->query,
                $this->dimension === 'pic' ? 'pic_id' : ($config['column'] ?? $this->dimension),
                $isJson,
                (string) $gs['value'],
                $gs['label']
            );
        }

        return $sheets;
    }
}
