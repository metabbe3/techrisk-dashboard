<?php

namespace App\Exports;

use App\Enums\Severity;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One sheet per group value (division / business category / root cause / PIC).
 * Incidents with multiple values appear in each matching sheet (owner-approved).
 */
class PerCategorySheet implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    protected Builder $baseQuery;

    protected string $column;

    /** @var string[] JSON array column or scalar column */
    protected bool $isJsonArray;

    protected string $groupValue;

    public function __construct(Builder $baseQuery, string $column, bool $isJsonArray, string $groupValue)
    {
        $this->baseQuery = $baseQuery;
        $this->column = $column;
        $this->isJsonArray = $isJsonArray;
        $this->groupValue = $groupValue;
    }

    public function title(): string
    {
        // Excel sheet titles: max 31 chars, no []:*?/\
        return substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '-', $this->groupValue), 0, 31);
    }

    public function query()
    {
        $q = $this->baseQuery->clone()->orderBy('incident_date', 'asc');

        return $this->isJsonArray
            ? $q->whereJsonContains($this->column, $this->groupValue)
            : $q->where($this->column, $this->groupValue);
    }

    public function headings(): array
    {
        return ['ID', 'Title', 'Date', 'Severity', 'Status', 'MTTR', 'MTBF (days)', 'PIC', 'Potential Loss', 'Actual Loss', 'Recovered'];
    }

    public function map($incident): array
    {
        return [
            $incident->no,
            $incident->title,
            $incident->incident_date?->format('d M Y'),
            $incident->severity instanceof \BackedEnum ? $incident->severity->value : $incident->severity,
            $incident->incident_status instanceof \BackedEnum ? $incident->incident_status->value : $incident->incident_status,
            $incident->mttr_formatted ?? ($incident->mttr !== null ? $incident->mttr : '-'),
            $incident->mtbf,
            $incident->pic?->name ?? '-',
            (float) $incident->potential_fund_loss,
            (float) $incident->fund_loss,
            (float) $incident->recovered_fund,
        ];
    }
}
