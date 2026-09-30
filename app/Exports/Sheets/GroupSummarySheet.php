<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Exports\Concerns\IdrFormat;

/**
 * Front summary: one row per group with counts and MTTR/MTBF aggregates.
 */
class GroupSummarySheet implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\ShouldAutoSize, \Maatwebsite\Excel\Concerns\WithColumnFormatting, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithMapping, \Maatwebsite\Excel\Concerns\WithTitle
{
    protected array $groupStats;

    protected string $label;

    public function __construct(array $groupStats, string $label)
    {
        $this->groupStats = $groupStats;
        $this->label = $label;
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function headings(): array
    {
        return [$this->label, 'Cases', 'Avg MTTR (min)', 'Avg MTTR (days)', 'Avg MTBF (days)', 'Cases w/ MTTR', 'Potential Loss', 'Actual Loss', 'Recovered'];
    }

    public function collection()
    {
        return collect($this->groupStats);
    }

    public function map($row): array
    {
        return [
            $row['label'],
            $row['count'],
            $row['avgMttrMins'],
            $row['avgMttrDays'],
            $row['avgMtbf'],
            $row['mttrDataCount'],
            $row['potential'],
            $row['actual'],
            $row['recovered'],
        ];
    }

    public function columnFormats(): array
    {
        // G=Potential Loss, H=Actual Loss, I=Recovered
        return ['G' => IdrFormat::FORMAT, 'H' => IdrFormat::FORMAT, 'I' => IdrFormat::FORMAT];
    }
}
