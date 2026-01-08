<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment; // Added

class IncidentTableExport implements FromCollection, WithHeadings, WithMapping, WithEvents, ShouldAutoSize
{
    protected $incidents;
    protected $stats;
    protected $headings;
    protected $columnNames;

    public function __construct($incidents, $stats, $headings, $columnNames)
    {
        $this->incidents = $incidents;
        $this->stats = $stats;
        $this->headings = $headings;
        $this->columnNames = $columnNames;
    }

    public function collection()
    {
        return $this->incidents;
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
            if ($columnName === 'recovery_rate') {
                 if ($incident->potential_fund_loss > 0) {
                    $rate = ($incident->recovered_fund / $incident->potential_fund_loss) * 100;
                    $row[] = number_format($rate, 1) . '%';
                } else {
                    $row[] = '-';
                }
            } elseif ($isBoolean) {
                $row[] = $incident->{$columnName} ? 'Yes' : 'No';
            }
            else {
                 $row[] = $incident->{$columnName};
            }
        }
        return $row;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastDataRow = $sheet->getHighestDataRow();
                $lastDataColumn = $sheet->getHighestDataColumn();
                
                // 1. Style Header (Yellow)
                $headerRange = 'A1:' . $lastDataColumn . '1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFEB9C'); // Yellow
                $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Center align header

                // 2. Zebra Striping for Data Rows (Sky Blue) & Center Alignment
                for ($row = 2; $row <= $lastDataRow; $row++) {
                    $rowRange = 'A' . $row . ':' . $lastDataColumn . $row;
                    if ($row % 2 == 0) { // Even rows
                        $sheet->getStyle($rowRange)->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFDDEBF7'); // Light Sky Blue
                    }
                    $sheet->getStyle($rowRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                // 3. Add Borders to Main Table
                $dataRange = 'A1:' . $lastDataColumn . $lastDataRow;
                $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // --- 4. Summary Row Logic & Styling ---
                $summaryStartRow = $lastDataRow + 2;

                $sheet->setCellValue("A{$summaryStartRow}", 'Summary For Displayed Data');
                $sheet->getStyle("A{$summaryStartRow}")->getFont()->setBold(true);

                $summaryHeaderRow = $summaryStartRow + 1;
                $summaryHeaders = ['Total Cases', 'Avg MTTR', 'Avg MTBF', 'Total Potential Loss', 'Total Actual Loss', 'Total Recovered'];
                $sheet->fromArray($summaryHeaders, null, "A{$summaryHeaderRow}");
                $summaryHeaderRange = "A{$summaryHeaderRow}:F{$summaryHeaderRow}";
                $sheet->getStyle($summaryHeaderRange)->getFont()->setBold(true);
                $sheet->getStyle($summaryHeaderRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C'); // Yellow
                $sheet->getStyle($summaryHeaderRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Center align summary headers

                $summaryDataRow = $summaryStartRow + 2;
                $summaryData = [
                    $this->stats['totalCases'],
                    $this->stats['avgMttr'],
                    $this->stats['avgMtbf'],
                    'Rp ' . number_format($this->stats['totalPotentialFundLoss'], 0, ',', '.'),
                    'Rp ' . number_format($this->stats['totalFundLoss'], 0, ',', '.'),
                    'Rp ' . number_format($this->stats['totalRecoveredFund'], 0, ',', '.'),
                ];
                $sheet->fromArray($summaryData, null, "A{$summaryDataRow}");
                $summaryDataRange = "A{$summaryDataRow}:F{$summaryDataRow}";
                $sheet->getStyle($summaryDataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Center align summary data

                // Add Borders to Summary
                $summaryRange = "A{$summaryHeaderRow}:F{$summaryDataRow}";
                $sheet->getStyle($summaryRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }
}
