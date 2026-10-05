<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Enums\FundStatus;
use App\Exports\Concerns\ComputesMtbfSequence;
use App\Exports\Concerns\IdrFormat;
use App\Exports\Concerns\QuarterRange;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * One case tab of the Quarterly Report (All Cases / Recovered Cases /
 * Fund Loss / Non Fund Loss) — the owner's hand-made quarterly report
 * columns, fixed. MTBF uses the full-year gap sequence (the report reads
 * as one table), not the per-tab sequence of the All Tabs export.
 */
class QuarterlyCaseSheet implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithTitle
{
    use ComputesMtbfSequence;

    private $query;

    private string $title;

    public function __construct($query, string $title)
    {
        $this->query = $query;
        $this->title = $title;
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
        return ['ID', 'Title', 'MTBF (days)', 'Severity', 'Incident Status', 'Quarter', 'Incident Date',
            'Actual Fund Loss', 'Fund Status', 'Domain', 'Root Cause Category', 'Responsible Team'];
    }

    public function map($incident): array
    {
        $severity = $incident->severity;
        $status = $incident->incident_status;
        $domain = $incident->business_category;
        $rootCause = $incident->root_cause_category;
        $team = $incident->responsible_team;
        $fundStatus = $incident->fund_status;

        return [
            $incident->no,
            $incident->title,
            // Outlier rows keep their place with a literal instead of a
            // gap value (owner rule 2026-10-01); sequence skips them.
            $incident->isOutlier() ? 'Outlier' : ($this->mtbfSequenceValue($incident) ?? '-'),
            $severity instanceof \BackedEnum ? $severity->value : $severity,
            $status instanceof \BackedEnum ? $status->value : $status,
            QuarterRange::label($incident->incident_date->year.'-Q'.$incident->incident_date->quarter),
            $incident->incident_date?->format('Y-m-d H:i:s'),
            (float) $incident->fund_loss,
            $fundStatus instanceof FundStatus ? $fundStatus->label() : ($fundStatus ?? ''),
            is_array($domain) ? implode(', ', $domain) : ($domain ?? ''),
            is_array($rootCause) ? implode(', ', $rootCause) : ($rootCause ?? ''),
            is_array($team) ? implode(', ', $team) : ($team ?? ''),
        ];
    }

    public function columnFormats(): array
    {
        // H = Actual Fund Loss, the only fund column — raw floats + native Rp format.
        return ['H' => IdrFormat::FORMAT];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastDataRow = $sheet->getHighestRow();
                $lastDataColumn = $sheet->getHighestDataColumn();
                $fullRange = 'A1:'.$lastDataColumn.$lastDataRow;

                $sheet->getStyle($fullRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $headerRange = 'A1:'.$lastDataColumn.'1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');

                for ($row = 2; $row <= $lastDataRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle('A'.$row.':'.$lastDataColumn.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');
                    }
                }

                $sheet->getStyle($fullRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // Footer: case count + Avg MTBF = mean of the displayed column
                // ('-' / 'Outlier' cells drop out) — reconciliation rule pinned
                // in ExportMtbfReconciliationTest.
                $rows = $this->query->clone()->with('labels')->get();
                $mtbfValues = $rows
                    ->map(fn ($i) => $this->mtbfSequenceValue($i))
                    ->filter(fn ($v) => $v !== null);
                $avgMtbf = $mtbfValues->isEmpty() ? 0 : round((float) $mtbfValues->avg(), 3);

                $footerRow = $lastDataRow + 2;
                $sheet->fromArray(['Total Cases', $rows->count(), 'Avg MTBF (days)', $avgMtbf], null, "A{$footerRow}");
                $sheet->getStyle("A{$footerRow}:D{$footerRow}")->getFont()->setBold(true);
            },
        ];
    }
}
