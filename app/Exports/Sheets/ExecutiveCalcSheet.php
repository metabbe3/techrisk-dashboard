<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Exports\Concerns\IdrFormat;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCharts;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

class ExecutiveCalcSheet implements FromCollection, ShouldAutoSize, WithCharts, WithColumnFormatting, WithHeadings, WithTitle
{
    protected Builder $query;

    /** @var array{months: string[], counts: int[], mttr: float[], pot: float[], rec: float[], sev: array, kpi: array} */
    public array $agg;

    public function __construct(Builder $query)
    {
        $this->query = $query->clone();
        $this->agg = $this->compute();
    }

    public function title(): string
    {
        return 'Calc';
    }

    public function headings(): array
    {
        return ['Month', 'Incidents', 'Severity', 'SevCount', 'AvgMTTR', 'Potential', 'Recovered'];
    }

    public function columnFormats(): array
    {
        // F=Potential, G=Recovered (monthly fund columns)
        return ['F' => IdrFormat::FORMAT, 'G' => IdrFormat::FORMAT];
    }

    public function charts(): array
    {
        // Calc sheet rows 2..13 (row 1 = headings)
        $months = new DataSeriesValues('String', 'Calc!$A$2:$A$13', null, 12);

        $incData = new DataSeriesValues('Number', 'Calc!$B$2:$B$13', null, 12);
        $incChart = new Chart(
            'chart_incidents_month',
            new Title('Incidents per Month'),
            null,
            new PlotArea(null, [
                new DataSeries(DataSeries::TYPE_BARCHART, null, range(0, 0), [], [$months], [$incData]),
            ])
        );
        $incChart->setTopLeftPosition('B16');
        $incChart->setBottomRightPosition('H31');

        $sevLabels = new DataSeriesValues('String', 'Calc!$C$2:$C$9', null, 8);
        $sevData = new DataSeriesValues('Number', 'Calc!$D$2:$D$9', null, 8);
        $sevChart = new Chart(
            'chart_severity_mix',
            new Title('Severity Mix'),
            new Legend(Legend::POSITION_RIGHT),
            new PlotArea(null, [
                new DataSeries(DataSeries::TYPE_PIECHART, null, range(0, 0), [], [$sevLabels], [$sevData]),
            ])
        );
        $sevChart->setTopLeftPosition('J16');
        $sevChart->setBottomRightPosition('P31');

        $mttrData = new DataSeriesValues('Number', 'Calc!$E$2:$E$13', null, 12);
        $mttrChart = new Chart(
            'chart_mttr_trend',
            new Title('Avg MTTR (minutes) per Month'),
            null,
            new PlotArea(null, [
                new DataSeries(DataSeries::TYPE_LINECHART, null, range(0, 0), [], [$months], [$mttrData]),
            ])
        );
        $mttrChart->setTopLeftPosition('B34');
        $mttrChart->setBottomRightPosition('H49');

        $potData = new DataSeriesValues('Number', 'Calc!$F$2:$F$13', null, 12);
        $recData = new DataSeriesValues('Number', 'Calc!$G$2:$G$13', null, 12);
        $fundChart = new Chart(
            'chart_funds',
            new Title('Potential vs Recovered Funds'),
            new Legend(Legend::POSITION_BOTTOM),
            new PlotArea(null, [
                new DataSeries(DataSeries::TYPE_BARCHART, null, range(0, 1), [], [$months], [$potData, $recData]),
            ])
        );
        $fundChart->setTopLeftPosition('J34');
        $fundChart->setBottomRightPosition('P49');

        return [$incChart, $sevChart, $mttrChart, $fundChart];
    }

    public function collection()
    {
        // 12 monthly rows + severity rows below (cols D/E) — chart series read A/G/F/G/H ranges.
        $rows = [];
        $nSev = count($this->agg['sev']);
        for ($i = 0; $i < max(12, $nSev); $i++) {
            $rows[] = [
                $this->agg['months'][$i] ?? null,
                $this->agg['counts'][$i] ?? null,
                $this->agg['sev'][$i][0] ?? null,
                $this->agg['sev'][$i][1] ?? null,
                $this->agg['mttr'][$i] ?? null,
                $this->agg['pot'][$i] ?? null,
                $this->agg['rec'][$i] ?? null,
            ];
        }

        return collect($rows);
    }

    private function compute(): array
    {
        $rows = $this->query->get();
        // EnumCast returns BackedEnum instances — Collection where() against the
        // string value never matches (enum == string is always false in PHP 8),
        // so every enum-field comparison goes through its ->value first.
        $sevOf = fn ($i) => $i->severity?->value ?? $i->severity;
        $statusOf = fn ($i) => $i->incident_status?->value ?? $i->incident_status;
        $eligible = $rows->filter(fn ($i) => in_array($sevOf($i), Severity::METRIC_ELIGIBLE));

        $base = now()->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $base->copy()->addMonths($i)->format('M y');
        }

        $counts = array_fill(0, 12, 0);
        $mttrSum = array_fill(0, 12, 0);
        $mttrN = array_fill(0, 12, 0);
        $pot = array_fill(0, 12, 0);
        $rec = array_fill(0, 12, 0);
        foreach ($rows as $i) {
            if (! $i->incident_date) {
                continue;
            }
            $idx = 11 - $i->incident_date->startOfMonth()->diffInMonths(now()->startOfMonth());
            if ($idx < 0 || $idx > 11) {
                continue;
            }
            $counts[$idx]++;
            $pot[$idx] += (float) $i->potential_fund_loss;
            $rec[$idx] += (float) $i->recovered_fund;
            if (in_array($sevOf($i), Severity::METRIC_ELIGIBLE) && $i->mttr !== null && $i->mttr >= 0) {
                $mttrSum[$idx] += $i->mttr;
                $mttrN[$idx]++;
            }
        }
        $mttrAvg = array_map(fn ($sv, $n) => $n > 0 ? round($sv / $n, 1) : 0, $mttrSum, $mttrN);

        // Owner rule (2026-09-29): severity breakdown is metric-eligible only —
        // G and Non Incident are excluded from the pie (and its 8-row range).
        $sev = [];
        foreach (Severity::METRIC_ELIGIBLE as $sevValue) {
            $sev[] = [$sevValue, $rows->filter(fn ($i) => $sevOf($i) === $sevValue)->count()];
        }

        // BUG-021: cast aggregates for round()/abs() under strict_types — uniform
        // pattern even though Collection::avg() returns float (MySQL rule).
        $avgMttrMins = round((float) ($eligible->where('mttr', '>=', 0)->avg('mttr') ?? 0), 1);
        $avgMttrDays = round(abs((float) ($eligible->where('mttr', '<', 0)->avg('mttr') ?? 0)), 1);
        $avgMtbf = app(\App\Filament\Statistics\IncidentStatsFooterData::class)->build($this->query)['avgMtbf'];
        $potential = (float) $rows->sum('potential_fund_loss');
        $recovered = (float) $rows->sum('recovered_fund');

        $kpi = [
            'totalCases' => $rows->count(),
            'open' => $rows->filter(fn ($i) => $statusOf($i) !== IncidentStatus::Completed->value)->count(),
            'avgMttrMins' => $avgMttrMins,
            'avgMttrDays' => $avgMttrDays,
            'avgMtbf' => $avgMtbf,
            'potential' => $potential,
            'recovered' => $recovered,
            'actual' => (float) $rows->sum('fund_loss'),
            'recoveryRate' => $potential > 0 ? round(($recovered / $potential) * 100, 1) : 0,
        ];

        return compact('months', 'counts', 'mttrAvg', 'pot', 'rec', 'sev', 'kpi') + ['mttr' => $mttrAvg];
    }
}
