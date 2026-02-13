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

class SingleIncidentSheetExport implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    private $query;

    private $title;

    private $stats;

    private $headings;

    private $columnNames;

    public function __construct($query, string $title, array $headings, array $columnNames)
    {
        $this->query = $query;
        $this->title = $title;
        $this->headings = $headings;
        $this->columnNames = $columnNames;
    }

    public function query()
    {
        return $this->query;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function map($incident): array
    {
        $row = [];
        foreach ($this->columnNames as $columnName) {
            $isBoolean = in_array($columnName, ['glitch_flag', 'risk_incident_form_cfm', 'goc_upload', 'teams_upload', 'doc_signed']);

            if ($columnName === 'mttr') {
                // Format MTTR: fund loss in days, regular in minutes/hours
                if ($incident->mttr === null) {
                    $row[] = '-';
                } elseif ($incident->mttr < 0) {
                    // Fund loss - stored as negative days
                    $days = abs($incident->mttr);
                    $row[] = $days . ' day' . ($days > 1 ? 's' : '');
                } else {
                    // Regular incident - stored as minutes
                    $minutes = $incident->mttr;
                    if ($minutes < 60) {
                        $row[] = $minutes . ' min' . ($minutes > 1 ? 's' : '');
                    } else {
                        $hours = floor($minutes / 60);
                        $mins = $minutes % 60;
                        if ($hours >= 24) {
                            $days = floor($hours / 24);
                            $hours = $hours % 24;
                            $row[] = "{$days}d {$hours}h {$mins}m";
                        } else {
                            $row[] = "{$hours}h {$mins}m";
                        }
                    }
                }
            } elseif ($columnName === 'recovery_rate') {
                if ($incident->potential_fund_loss > 0) {
                    $rate = ($incident->recovered_fund / $incident->potential_fund_loss) * 100;
                    $row[] = number_format($rate, 1).'%';
                } else {
                    $row[] = '-';
                }
            } elseif ($isBoolean) {
                $row[] = $incident->{$columnName} ? 'Yes' : 'No';
            } else {
                $row[] = $incident->{$columnName};
            }
        }

        return $row;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Calculate stats for this specific sheet
                $query = $this->query->clone();
                $avgMttr = round($query->where('mttr', '>=', 0)->avg('mttr'), 2); // Exclude fund loss (negative values)
                $this->stats = [
                    'totalCases' => $query->count(),
                    'avgMttr' => $avgMttr,
                    'avgMtbf' => round($query->avg('mtbf'), 2),
                    'totalPotentialFundLoss' => $query->sum('potential_fund_loss'),
                    'totalFundLoss' => $query->sum('fund_loss'),
                    'totalRecoveredFund' => $query->sum('recovered_fund'),
                ];

                $lastDataRow = $sheet->getHighestRow();
                $lastDataColumn = $sheet->getHighestDataColumn();
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

                $summaryStartRow = $lastDataRow + 2;
                $sheet->setCellValue("A{$summaryStartRow}", 'Summary For This Sheet');
                $sheet->getStyle("A{$summaryStartRow}")->getFont()->setBold(true);
                $sheet->getStyle("A{$summaryStartRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $summaryHeaderRow = $summaryStartRow + 1;
                $summaryHeaders = ['Total Cases', 'Avg MTTR', 'Avg MTBF', 'Total Potential Loss', 'Total Actual Loss', 'Total Recovered'];
                $sheet->fromArray($summaryHeaders, null, "A{$summaryHeaderRow}");
                $summaryHeaderRange = "A{$summaryHeaderRow}:F{$summaryHeaderRow}";
                $sheet->getStyle($summaryHeaderRange)->getFont()->setBold(true);
                $sheet->getStyle($summaryHeaderRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');
                $sheet->getStyle($summaryHeaderRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $summaryDataRow = $summaryStartRow + 2;
                $summaryData = [
                    $this->stats['totalCases'], $this->stats['avgMttr'], $this->stats['avgMtbf'],
                    'Rp '.number_format($this->stats['totalPotentialFundLoss'], 0, ',', '.'),
                    'Rp '.number_format($this->stats['totalFundLoss'], 0, ',', '.'),
                    'Rp '.number_format($this->stats['totalRecoveredFund'], 0, ',', '.'),
                ];
                $sheet->fromArray($summaryData, null, "A{$summaryDataRow}");
                $summaryDataRange = "A{$summaryDataRow}:F{$summaryDataRow}";
                $sheet->getStyle($summaryDataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $summaryRange = "A{$summaryHeaderRow}:F{$summaryDataRow}";
                $sheet->getStyle($summaryRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }
}
