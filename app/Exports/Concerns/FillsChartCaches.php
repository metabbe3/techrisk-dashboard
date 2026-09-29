<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fills native chart value caches (numCache/strCache) that PhpSpreadsheet
 * omits — without them Excel renders fine (recalc on open) but Numbers,
 * QuickLook and Google Sheets show empty charts (BUG-013).
 *
 * Usage inside registerEvents(): after the write, walk each sheet's charts,
 * resolve each series ref against the worksheet's cell values and inject
 * <c:pt> entries via the chart object's cache API.
 */
trait FillsChartCaches
{
    /**
     * @param  array<int, Worksheet>  $sheets  sheets that own charts
     */
    protected function fillChartCaches(array $sheets): void
    {
        foreach ($sheets as $sheet) {
            foreach ($sheet->getChartCollection() as $chart) {
                if (! $chart instanceof Chart) {
                    continue;
                }
                $this->fillPlotGroupCaches($chart, $sheet);
            }
        }
    }

    private function fillPlotGroupCaches(Chart $chart, Worksheet $sheet): void
    {
        foreach ($chart->getPlotArea()?->getPlotGroup() ?? [] as $group) {
            foreach ($group->getPlotLabels() as $label) {
                $this->cacheFromRef($label, $sheet, isString: true);
            }
            foreach ($group->getPlotValues() as $values) {
                $this->cacheFromRef($values, $sheet, isString: false);
            }
        }
    }

    private function cacheFromRef(DataSeriesValues $dsv, Worksheet $sheet, bool $isString): void
    {
        $ref = $dsv->getDataSource();
        if ($ref === '' || ! str_contains($ref, '!')) {
            return;
        }

        [$sheetName, $range] = explode('!', $ref, 2);
        $sheetName = trim($sheetName, "'\"");
        $target = $sheetName === '' ? $sheet : $sheet->getParent()?->getSheetByNameOrThrow($sheetName);

        // Only single-column/row ranges ("A2:A13") — skip unions/complex refs.
        if (! preg_match('/^\\$?([A-Z]+)\\$?(\d+):\\$?([A-Z]+)\\$?(\d+)$/', $range, $m)) {
            return;
        }
        [, $c1, $r1, $c2, $r2] = $m;
        if ($c1 !== $c2 && $r1 !== $r2) {
            return; // 2D range unsupported
        }

        $values = [];
        for ($r = (int) $r1; $r <= (int) $r2; $r++) {
            for ($c = $c1; $c2 >= $c1 && $c <= $c2 || $c2 < $c1 && $c >= $c2; $c = $c2 >= $c1 ? ++$c : --$c) {
                $cell = $target->getCell("{$c}{$r}");
                $v = $cell->getValue();
                $values[] = $v === null ? null : (is_scalar($v) ? (string) $v : null);
                if ($c === $c2) {
                    break;
                }
            }
        }

        $dsv->setDataValues(array_values(array_filter($values, fn ($v) => $v !== null)));
        // Refresh format flags so the writer emits ptCount + <c:pt> list.
        if (! $isString) {
            $dsv->setFormatCode($dsv->getFormatCode() ?? 'General');
        }
    }
}
