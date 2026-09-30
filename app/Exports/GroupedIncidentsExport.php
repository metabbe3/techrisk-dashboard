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
        'quarter' => ['label' => 'Quarter', 'json' => false],
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
        } elseif ($this->dimension === 'quarter') {
            // "YYYY-Qn" strings: string sort = chronological, and the same
            // quarter in two years stays two distinct groups.
            $values = $rows
                ->filter(fn ($i) => $i->incident_date !== null)
                ->map(fn ($i) => $i->incident_date->year.'-Q'.$i->incident_date->quarter)
                ->unique()->sort()->values();
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
                if ($this->dimension === 'quarter') {
                    // No backing column — deriving from the datetime cast.
                    $d = $i->incident_date;

                    return $d !== null && "{$d->year}-Q{$d->quarter}" === $value;
                }
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
            // Widget alignment (owner rule 2026-09-30): Summary numbers follow
            // the dashboard cards — counts/loss sums exclude the same fund
            // statuses FundStatus::EXCLUDED_FROM_COUNTS excludes, and Actual /
            // Potential apply the Fund Loss / Potential Fund Loss card status
            // rules. The excluded rows get their own tabs below, nothing is lost.
            $widgetRows = $eligible->filter(fn ($i) => ! in_array(
                $i->fund_status?->value ?? $i->fund_status,
                \App\Enums\FundStatus::EXCLUDED_FROM_COUNTS
            ));
            $statusOf = fn ($i) => $i->incident_status?->value ?? $i->incident_status; // enum-cast (BUG-022 lesson)
            $completed = \App\Enums\IncidentStatus::Completed->value;
            // avg MTBF per group: (max-min date among eligible) / (count-1)
            $eligibleDates = $eligible->pluck('incident_date')->filter()->sort()->values();
            $avgMtbf = 0;
            if ($eligibleDates->count() > 1) {
                $spanDays = $eligibleDates->first()->startOfDay()->diffInDays($eligibleDates->last()->startOfDay());
                $avgMtbf = round($spanDays / ($eligibleDates->count() - 1), 1);
            }
            $label = (string) $value;
            if ($this->dimension === 'pic') {
                $label = $names[$value] ?? "PIC {$value}";
            } elseif ($this->dimension === 'quarter') {
                // "2026-Q1" -> "Q1 2026"
                [$y, $q] = explode('-Q', $label);
                $label = "Q{$q} {$y}";
            }
            $groupStats[] = [
                'label' => $label,
                'value' => $value,
                'count' => $widgetRows->count(),
                // BUG-021: cast aggregates for round()/abs() under strict_types — uniform
                // pattern even though Collection::avg() returns float (MySQL rule).
                'avgMttrMins' => round((float) ($eligible->where('mttr', '>=', 0)->avg('mttr') ?? 0), 1),
                'avgMttrDays' => round(abs((float) ($eligible->where('mttr', '<', 0)->avg('mttr') ?? 0)), 1),
                'avgMtbf' => $avgMtbf,
                'mttrDataCount' => $eligible->whereNotNull('mttr')->count(),
                // Potential Fund Loss card: open cases only. Fund Loss card:
                // Completed only. Recovered card: no fund-status exclusion.
                'potential' => (float) $widgetRows->reject(fn ($i) => $statusOf($i) === $completed)->sum('potential_fund_loss'),
                'actual' => (float) $widgetRows->filter(fn ($i) => $statusOf($i) === $completed)->sum('fund_loss'),
                'recovered' => (float) $eligible->sum('recovered_fund'),
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

        // Rows the Summary excludes by widget rule get their own tabs, so the
        // aligned numbers never hide data (owner rule 2026-09-30).
        foreach (\App\Enums\FundStatus::EXCLUDED_FROM_COUNTS as $fundStatus) {
            $hasRows = $rows->contains(fn ($i) => ($i->fund_status?->value ?? $i->fund_status) === $fundStatus);
            if ($hasRows) {
                $sheets[] = new PerCategorySheet(
                    $this->query,
                    'fund_status',
                    false,
                    $fundStatus,
                    'Excluded - '.\App\Enums\FundStatus::from($fundStatus)->label()
                );
            }
        }

        return $sheets;
    }
}
