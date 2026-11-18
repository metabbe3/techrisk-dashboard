<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Enums\IncidentClassification;
use App\Exports\Concerns\ComputesMtbfSequence;
use App\Exports\Concerns\WritesSeverityFooter;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class IssuesMetricSheetExport implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    use ComputesMtbfSequence;
    use WritesSeverityFooter;

    private $query;

    private $title;

    private $metricType;

    public function __construct($query, string $title, string $metricType)
    {
        $this->query = $query;
        $this->title = $title;
        $this->metricType = $metricType;
        // Shared chain implementation (owner one-logic rule 2026-11-16).
        // NOT the sheet's own query — it carries row-membership clauses
        // (whereNotNull mttr/mtbf + outliers) that must never scope the
        // sequence (the old private impl chained ALL eligible Issues of
        // the year; missing stored metrics still anchored gaps). The scope
        // pins classification = Issue; the trait adds metric hygiene.
        $this->mtbfScope = \App\Models\Incident::query()
            ->where('classification', IncidentClassification::Issue->value);
    }

    public function query()
    {
        return $this->query->with(['incidentType']);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function headings(): array
    {
        $metricLabel = $this->metricType === 'mttr' ? 'MTTR (mins)' : 'MTBF (days)';

        return ['Issue Name', 'Type', $metricLabel];
    }

    public function map($incident): array
    {
        if ($this->metricType === 'mttr') {
            $metricValue = $incident->mttr_formatted;
        } else {
            // Outlier rows keep their place with a literal (owner rule
            // 2026-10-01); the gap sequence skips them.
            $metricValue = $incident->isOutlier() ? 'Outlier' : ($this->computeIssueMtbf($incident) ?? '-');
        }

        return [
            str_replace('Summary of Incident - ', '', $incident->title),
            $incident->incidentType?->name ?? 'N/A',
            $metricValue,
        ];
    }

    /**
     * Gap sequence over the year's eligible Issues via the shared
     * ComputesMtbfSequence implementation (same convention as every other
     * sheet): first of the year has no predecessor → null (renders '-'),
     * so the column's average equals span/(n-1) and the bottom is the mean
     * of the displayed column.
     */
    private function computeIssueMtbf($incident): ?int
    {
        return $this->mtbfSequenceValue($incident);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $lastDataRow = $sheet->getHighestRow();
                $lastDataColumn = 'C';
                $fullDataRange = 'A1:'.$lastDataColumn.$lastDataRow;

                $sheet->getStyle($fullDataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $headerRange = 'A1:'.$lastDataColumn.'1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');

                for ($row = 2; $row <= $lastDataRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle('A'.$row.':'.$lastDataColumn.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');
                    }
                }

                $sheet->getStyle($fullDataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // Summary - Total Cases and Average
                $summaryStartRow = $lastDataRow + 2;
                $totalCases = $this->query->clone()->count();

                if ($this->metricType === 'mttr') {
                    $regularMttr = $this->query->clone()
                        ->whereNotNull('mttr')
                        ->where('mttr', '>=', 0)
                        ->avg('mttr');

                    $metricLabel = 'Average MTTR (excl. fund loss)';
                    $metricValue = $regularMttr !== null ? round((float) $regularMttr, 2) : '-';
                } else {
                    // Avg MTBF = mean of the column AS DISPLAYED (first-of-year
                    // '-' drops out) — the bottom must equal the column above
                    // it; for a single-year set that is exactly span/(n-1).
                    $metricLabel = 'Average MTBF';
                    $mtbfValues = $this->query->clone()->get()
                        ->map(fn ($i) => $this->computeIssueMtbf($i))
                        ->filter(fn ($v) => $v !== null);
                    $metricValue = $mtbfValues->isEmpty() ? '-' : round((float) $mtbfValues->avg(), 3);
                }

                $sheet->setCellValue("A{$summaryStartRow}", 'Total Cases');
                $sheet->setCellValue("B{$summaryStartRow}", $totalCases);
                $sheet->setCellValue('A'.($summaryStartRow + 1), $metricLabel);
                $sheet->setCellValue('B'.($summaryStartRow + 1), $metricValue);

                $summaryRange = "A{$summaryStartRow}:B".($summaryStartRow + 1);
                $sheet->getStyle($summaryRange)->getFont()->setBold(true);
                $sheet->getStyle($summaryRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2EFDA');
                $sheet->getStyle($summaryRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle($summaryRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Severity + MTTR + fund counts below the two-row summary.
                $this->writeSeverityFooter($sheet, $summaryStartRow + 3, $this->query, $this->metricType === 'mtbf' ? $metricValue : null);
            },
        ];
    }
}
