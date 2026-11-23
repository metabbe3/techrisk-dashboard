<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use App\Enums\FundStatus;
use App\Enums\Severity;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Owner rule 2026-10-27 (restyled twice same day): every incident-listing
 * tab ends with two single-line banners, each MERGED across the table's
 * full width — a per-column grid inherits the incident table's column
 * widths and reads badly (owner: "mengikuti column size dari table incident
 * jadi tidak enak dilihat dan di baca"). Banner 1: severity counts P1..X4;
 * banner 2: stats. Avg MTTR is SPLIT by the storage convention
 * (IncidentMetricsCalculator, owner 2026-10-13): positive mttr = minutes,
 * negative mttr = elapsed days — each bucket averages in its own unit,
 * never mixed. Counts describe the tab's own rows, so tab-vs-widget
 * differences read straight off the sheet.
 */
trait WritesSeverityFooter
{
    /**
     * Writes the 2 banner rows (severity line, stats line) starting at
     * $row, each merged A..max(table width, L) so the text never sits in
     * the table's column grid.
     *
     * @param  Builder|Collection  $source  the tab's own filtered set —
     *                                      never a pre-grouped builder
     *                                      (Edit-Safety rule 6).
     * @param  float|string|null  $avgMtbf  the sheet's own Avg MTBF (days),
     *                                      already rounded by the caller —
     *                                      its summary row's value, so
     *                                      banner and summary can't drift.
     *                                      Null (stored-metric sheets with
     *                                      no sequence) omits the segment.
     */
    protected function writeSeverityFooter(Worksheet $sheet, int $row, $source, $avgMtbf = null): void
    {
        [$sevCounts, $fundCounts, $avgMttrMins, $avgMttrDays] = $source instanceof Collection
            ? $this->footerStatsFromCollection($source)
            : $this->footerStatsFromQuery($source);

        $sevLine = implode(' | ', array_map(
            fn (string $s) => $s.': '.($sevCounts[$s] ?? 0),
            Severity::METRIC_ELIGIBLE,
        ));
        // Unit-labeled on purpose — the days bucket holds every
        // day-granularity status (Confirmed loss, Potential recovery, Fully
        // recovered, Non Tech Loss), so a "Fund Loss" label here would
        // contradict the Confirmed-loss-only count in the same line.
        // BUG-021: avg() is a decimal-string on MySQL — cast before rounding.
        // MTBF comes pre-formatted from the caller's summary math (owner
        // 2026-10-30: banner mirrors the sheet's own Avg MTBF, each preset's
        // sequence semantics intact — per-tab for All-Tabs, full filtered
        // set for Quarterly/Custom).
        $statsLine = sprintf(
            'Avg MTTR (mins): %s | Avg MTTR (days): %s%s | Fund Loss: %d | Non Fund Loss: %d',
            $avgMttrMins === null ? '-' : round((float) $avgMttrMins, 2),
            $avgMttrDays === null ? '-' : round((float) $avgMttrDays, 2),
            $avgMtbf === null ? '' : ' | Avg MTBF (days): '.$avgMtbf,
            $fundCounts[FundStatus::ConfirmedLoss->value] ?? 0,
            $fundCounts[FundStatus::NonFundLoss->value] ?? 0,
        );

        // Banner spans the table's full width (min L so the ~95-char stats
        // line fits even on narrow Custom sheets — merged cells clip, they
        // do not overflow into neighbours).
        $lastCol = Coordinate::stringFromColumnIndex(max(
            Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
            12,
        ));

        foreach ([$row => $sevLine, $row + 1 => $statsLine] as $r => $line) {
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
            $sheet->setCellValue("A{$r}", $line);
            $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray(self::FOOTER_STYLE);
        }
    }

    /** Same visual language as the sheets' yellow summary headers. */
    private const FOOTER_STYLE = [
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFEB9C']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ];

    /**
     * @return array{0: Collection<string,int>, 1: Collection<string,int>, 2: float|int|null, 3: float|int|null}
     */
    private function footerStatsFromQuery(Builder $query): array
    {
        // ponytail: reorder() is load-bearing — call sites hand us ordered
        // builders and MySQL ONLY_FULL_GROUP_BY (strict mode) rejects ORDER BY
        // on a non-grouped column (error 1055); SQLite tests can't see it.
        // select([]) is load-bearing too: selectRaw() APPENDS, so the caller's
        // select columns ride along (the incidents table query carries
        // withCount/withSum sub-incident subqueries) and ONLY_FULL_GROUP_BY
        // rejects the grouped select with error 1055 — the prod 500 behind
        // the Recovered-This-Year deploy. The two avg() calls are safe:
        // aggregates replace the select list.
        // MTTR split by storage sign (owner 2026-10-13 convention):
        // >= 0 = minutes bucket, < 0 = elapsed-days bucket (averaged abs).
        $daysAvg = $query->clone()->reorder()->where('mttr', '<', 0)->avg('mttr');

        return [
            $query->clone()->reorder()->select([])->selectRaw('severity, COUNT(*) as count')->groupBy('severity')->pluck('count', 'severity'),
            $query->clone()->reorder()->select([])->selectRaw('fund_status, COUNT(*) as count')->groupBy('fund_status')->pluck('count', 'fund_status'),
            $query->clone()->reorder()->where('mttr', '>=', 0)->avg('mttr'),
            $daysAvg === null ? null : abs((float) $daysAvg),
        ];
    }

    /**
     * @return array{0: Collection<string,int>, 1: Collection<string,int>, 2: float|int|null, 3: float|int|null}
     */
    private function footerStatsFromCollection(Collection $incidents): array
    {
        $value = fn ($v) => $v instanceof BackedEnum ? $v->value : $v;
        $byDays = $incidents->filter(fn ($i) => $i->mttr !== null && $i->mttr < 0);
        $inMinutes = $incidents->filter(fn ($i) => $i->mttr !== null && $i->mttr >= 0);

        return [
            $incidents->countBy(fn ($i) => $value($i->severity)),
            $incidents->countBy(fn ($i) => $value($i->fund_status)),
            $inMinutes->isEmpty() ? null : $inMinutes->avg('mttr'),
            $byDays->isEmpty() ? null : abs((float) $byDays->avg('mttr')),
        ];
    }
}
