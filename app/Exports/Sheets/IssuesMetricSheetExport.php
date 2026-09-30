<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Enums\IncidentClassification;
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
    private $query;

    private $title;

    private $metricType;

    private array $mtbfCache = []; // instance, NOT static — static froze the sequence across requests in long-lived FPM workers (prod bug 2026-09-30)

    public function __construct($query, string $title, string $metricType)
    {
        $this->query = $query;
        $this->title = $title;
        $this->metricType = $metricType;
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
            $metricValue = $this->computeIssueMtbf($incident) ?? '-';
        }

        return [
            str_replace('Summary of Incident - ', '', $incident->title),
            $incident->incidentType?->name ?? 'N/A',
            $metricValue,
        ];
    }

    /**
     * Gap sequence over the year's eligible Issues, same convention as the
     * other sheets (2026-09-30 reconciliation): first of the year has no
     * predecessor → null (renders '-'), so the column's average equals
     * span/(n-1) and the bottom is the mean of the displayed column.
     */
    private function computeIssueMtbf($incident): ?int
    {
        $year = $incident->incident_date->year;
        $key = "export_issues_{$year}";

        if (! isset($this->mtbfCache[$key])) {
            $incidents = \App\Models\Incident::whereYear('incident_date', $year)
                ->where('classification', IncidentClassification::Issue->value)
                ->whereIn('severity', \App\Enums\Severity::METRIC_ELIGIBLE)
                ->orderBy('incident_date')->orderBy('id')
                ->get(['id', 'incident_date']);

            $this->mtbfCache[$key] = [];
            foreach ($incidents as $i => $inc) {
                $this->mtbfCache[$key][$inc->id] = $i === 0
                    ? null
                    : (int) $incidents[$i - 1]->incident_date->startOfDay()
                        ->diffInDays($inc->incident_date->startOfDay());
            }
        }

        return $this->mtbfCache[$key][$incident->id] ?? null;
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
            },
        ];
    }
}
