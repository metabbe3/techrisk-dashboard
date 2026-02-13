<?php

namespace App\Exports\Sheets;

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

    private $metricType; // 'mttr' or 'mtbf'

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
            // Format MTTR: fund loss in days, regular in minutes/hours
            if ($incident->mttr === null) {
                $metricValue = '-';
            } elseif ($incident->mttr < 0) {
                // Fund loss - stored as negative days
                $days = abs($incident->mttr);
                $metricValue = $days . ' day' . ($days > 1 ? 's' : '');
            } else {
                // Regular incident - stored as minutes
                $minutes = $incident->mttr;
                if ($minutes < 60) {
                    $metricValue = $minutes . ' min' . ($minutes > 1 ? 's' : '');
                } else {
                    $hours = floor($minutes / 60);
                    $mins = $minutes % 60;
                    if ($hours >= 24) {
                        $days = floor($hours / 24);
                        $hours = $hours % 24;
                        $metricValue = "{$days}d {$hours}h {$mins}m";
                    } else {
                        $metricValue = "{$hours}h {$mins}m";
                    }
                }
            }
        } else {
            // MTBF - always in days
            $metricValue = $incident->mtbf ?? '-';
        }

        return [
            str_replace('Summary of Incident - ', '', $incident->title),
            $incident->incidentType?->name ?? 'N/A',
            $metricValue,
        ];
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
                    // Calculate MTTR average (exclude fund loss incidents from average as they skew it)
                    $regularMttr = $this->query->clone()
                        ->whereNotNull('mttr')
                        ->where('mttr', '>=', 0) // Only regular incidents (positive minutes)
                        ->avg('mttr');

                    $metricLabel = 'Average MTTR (excl. fund loss)';
                    $metricValue = $regularMttr !== null ? round($regularMttr, 2) : '-';
                } else {
                    // MTBF average - all incidents
                    $metricLabel = 'Average MTBF';
                    $metricValue = round($this->query->clone()->avg('mtbf'), 2);
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
