<?php

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

    private static array $mtbfCache = [];

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
            $metricValue = $this->computeIssueMtbf($incident);
        }

        return [
            str_replace('Summary of Incident - ', '', $incident->title),
            $incident->incidentType?->name ?? 'N/A',
            $metricValue,
        ];
    }

    private function computeIssueMtbf($incident): int
    {
        $year = $incident->incident_date->year;
        $key = "export_issues_{$year}";

        if (! isset(self::$mtbfCache[$key])) {
            $incidents = \App\Models\Incident::whereYear('incident_date', $year)
                ->where('classification', IncidentClassification::Issue->value)
                ->orderBy('incident_date')->orderBy('id')
                ->get(['id', 'incident_date']);

            self::$mtbfCache[$key] = [];
            foreach ($incidents as $i => $inc) {
                self::$mtbfCache[$key][$inc->id] = $i === 0
                    ? $inc->incident_date->dayOfYear
                    : (int) $incidents[$i - 1]->incident_date->startOfDay()
                        ->diffInDays($inc->incident_date->startOfDay());
            }
        }

        return self::$mtbfCache[$key][$incident->id] ?? 0;
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
                    $query = $this->query->clone();
                    $metricLabel = 'Average MTBF';
                    $metricValue = 0;
                    if ($totalCases > 0) {
                        $minDate = $query->min('incident_date');
                        $maxDate = $query->max('incident_date');

                        if ($minDate && $maxDate) {
                            $minDate = \Carbon\Carbon::parse($minDate)->startOfDay();
                            $maxDate = \Carbon\Carbon::parse($maxDate)->startOfDay();
                            $totalDays = $minDate->diffInDays($maxDate);
                            $metricValue = $totalCases > 1 ? round($totalDays / ($totalCases - 1), 3) : 0;
                        }
                    }
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
