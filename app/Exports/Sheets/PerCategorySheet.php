<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Exports\Concerns\IdrFormat;
use App\Exports\Concerns\QuarterRange;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One sheet per group value (division / business category / root cause / PIC).
 * Incidents with multiple values appear in each matching sheet (owner-approved).
 */
class PerCategorySheet implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    protected Builder $baseQuery;

    protected string $column;

    /** @var string[] JSON array column or scalar column */
    protected bool $isJsonArray;

    protected string $groupValue;

    protected ?string $sheetTitle;

    public function __construct(Builder $baseQuery, string $column, bool $isJsonArray, string $groupValue, ?string $sheetTitle = null)
    {
        $this->baseQuery = $baseQuery;
        $this->column = $column;
        $this->isJsonArray = $isJsonArray;
        $this->groupValue = $groupValue;
        $this->sheetTitle = $sheetTitle;
    }

    public function title(): string
    {
        // Excel sheet titles: max 31 chars, no []:*?/\
        $title = $this->sheetTitle ?? $this->groupValue;

        return substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '-', $title), 0, 31);
    }

    public function query()
    {
        $q = $this->baseQuery->clone()->orderBy('incident_date', 'asc');

        if ($this->column === 'quarter') {
            // Sentinel column: quarter has no backing DB column (Laravel has
            // no whereQuarter) — filter by date range instead.
            [$start, $end] = QuarterRange::dates($this->groupValue);

            return $q->whereBetween('incident_date', [$start, $end]);
        }

        if ($this->column === 'pic') {
            // Sentinel column: PICs live on the incident_pic pivot — filter by
            // relation, never by a column (Edit-Safety rule 4).
            return $q->whereHas('pics', fn ($pic) => $pic->where('users.id', (int) $this->groupValue));
        }

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
            $incident->mttr_formatted,
            // Outlier: literal (owner rule 2026-10-01). Non-outlier null
            // stored mtbf renders 0, not blank (owner rule, same day).
            $incident->isOutlier() ? 'Outlier' : ($incident->mtbf ?? 0),
            $incident->pic_names !== '' ? $incident->pic_names : '-',
            (float) $incident->potential_fund_loss,
            (float) $incident->fund_loss,
            (float) $incident->recovered_fund,
        ];
    }

    public function columnFormats(): array
    {
        // I=Potential Loss, J=Actual Loss, K=Recovered (values are float-cast in map())
        return ['I' => IdrFormat::FORMAT, 'J' => IdrFormat::FORMAT, 'K' => IdrFormat::FORMAT];
    }
}
