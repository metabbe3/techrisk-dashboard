<?php

namespace App\Exports\Sheets;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
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

    private static array $mtbfCache = [];

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
            $isArray = in_array($columnName, ['business_category', 'root_cause_category', 'responsible_team']);

            if ($columnName === 'mtbf') {
                $row[] = $this->computeMtbfForIncident($incident);
            } elseif ($columnName === 'mttr') {
                $row[] = $incident->mttr_formatted;
            } elseif ($columnName === 'recovery_rate') {
                if ((float) $incident->potential_fund_loss > 0) {
                    $rate = ((float) $incident->recovered_fund / (float) $incident->potential_fund_loss) * 100;
                    $row[] = number_format($rate, 1).'%';
                } else {
                    $row[] = '-';
                }
            } elseif ($isBoolean) {
                $row[] = $incident->{$columnName} ? 'Yes' : 'No';
            } elseif ($isArray) {
                $value = $incident->{$columnName};
                $row[] = is_array($value) ? implode(', ', $value) : ($value ?? '');
            } else {
                $value = $incident->{$columnName};
                // ponytail: enum-cast attrs (severity/status/classification) return BackedEnum instances
                // PhpSpreadsheet can't stringify them → coerce to the stored scalar value.
                $row[] = $value instanceof \BackedEnum ? $value->value : $value;
            }
        }

        return $row;
    }

    private function computeMtbfForIncident($incident): int
    {
        $year = $incident->incident_date->year;
        $key = "export_{$this->title}_{$year}";

        if (! isset(self::$mtbfCache[$key])) {
            $query = \App\Models\Incident::whereYear('incident_date', $year)
                ->orderBy('incident_date')->orderBy('id');

            // Issues tab uses Issue classification; all others exclude Issues
            if ($this->title === 'All Issues') {
                $query->where('classification', IncidentClassification::Issue->value);
            } else {
                $query->where('classification', '!=', IncidentClassification::Issue->value);
            }

            match ($this->title) {
                'On Going' => $query->where('incident_status', '!=', 'Completed'),
                'Completed Cases' => $query->where('incident_status', 'Completed'),
                'Recovered Cases' => $query->where('recovered_fund', '>', 0),
                'P4 Incidents' => $query->where('severity', 'P4'),
                'Non-Tech Incidents' => $query->where('incident_type', 'Non-tech'),
                'Fund Loss' => $query->where('fund_status', 'Confirmed loss'),
                'Potential Recovery' => $query->where('fund_status', 'Potential recovery'),
                'Fully Recovered' => $query->where('fund_status', 'Fully recovered'),
                'Non Tech Loss' => $query->where('fund_status', 'Non Tech Loss'),
                'Non Fund Loss' => $query->where('fund_status', 'Non fundLoss'),
                'Non Incident' => $query->where('severity', 'Non Incident'),
                default => null,
            };

            $incidents = $query->get(['id', 'incident_date']);
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

                // Calculate stats for this specific sheet
                $query = $this->query->clone();
                $totalCases = $query->count();

                // MTTR average (exclude fund loss incidents with negative values)
                $avgMttr = round($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr') ?? 0, 2);

                // Calculate MTBF correctly: Total Time Period / Number of Incidents
                $mtbfQuery = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE);
                $mtbfCount = $mtbfQuery->count();
                $avgMtbf = 0;
                if ($mtbfCount > 0) {
                    $minDate = $mtbfQuery->min('incident_date');
                    $maxDate = $mtbfQuery->max('incident_date');

                    if ($minDate && $maxDate) {
                        $minDate = \Carbon\Carbon::parse($minDate)->startOfDay();
                        $maxDate = \Carbon\Carbon::parse($maxDate)->startOfDay();
                        $totalDays = $minDate->diffInDays($maxDate);
                        $avgMtbf = $mtbfCount > 1 ? round($totalDays / ($mtbfCount - 1), 3) : 0;
                    }
                }

                $this->stats = [
                    'totalCases' => $totalCases,
                    'avgMttr' => $avgMttr,
                    'avgMtbf' => $avgMtbf,
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
