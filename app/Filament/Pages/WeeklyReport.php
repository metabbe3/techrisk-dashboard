<?php

namespace App\Filament\Pages;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WeeklyReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationLabel = 'Weekly Report';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.weekly-report';

    protected static bool $isDiscovered = true;

    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('access dashboard');
    }

    public ?int $selectedYear = null;

    public int $perPage = 10;

    public int $currentPage = 1;

    private ?array $cachedWeeklyData = null;

    public function mount(): void
    {
        $this->selectedYear = (int) date('Y');
        $this->currentPage = request()->get('page', 1);
        $this->form->fill(['selectedYear' => $this->selectedYear]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('selectedYear')
                    ->label('Year')
                    ->options($this->getYearOptions())
                    ->default($this->selectedYear)
                    ->live()
                    ->afterStateUpdated(fn () => $this->currentPage = 1),
            ]);
    }

    protected function getYearOptions(): array
    {
        $currentYear = (int) date('Y');
        $years = [];

        for ($i = 0; $i < 6; $i++) {
            $year = $currentYear - $i;
            $years[$year] = (string) $year;
        }

        return $years;
    }

    public function getPaginatedWeeklyData(): LengthAwarePaginator
    {
        $weeklyData = $this->getWeeklyData();
        $perPage = request()->get('perPage', $this->perPage);
        $currentPage = request()->get('page', $this->currentPage);

        $total = count($weeklyData);
        $items = array_slice($weeklyData, ($currentPage - 1) * $perPage, $perPage);

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    public function getWeeklyData(): array
    {
        if ($this->cachedWeeklyData !== null) {
            return $this->cachedWeeklyData;
        }

        $currentDate = now();
        $weekData = [];

        $year = $this->selectedYear ?? (int) date('Y');
        $weeks = $this->getIsoWeeksInYear($year);

        // OPTIMIZED: Load all incidents for the year in a single query
        $allIncidents = Incident::where('classification', 'Incident')
            ->whereYear('incident_date', $year)
            ->where(fn ($query) => $query->whereNull('fund_status')
                ->orWhere('fund_status', '!=', FundStatus::PotentialRecovery->value))
            ->with(['pic', 'labels'])
            ->orderBy('incident_date', 'desc')
            ->get();

        $openStatuses = [
            IncidentStatus::Open->value,
            IncidentStatus::InProgress->value,
            IncidentStatus::Finalization->value,
        ];

        foreach ($weeks as $weekNumber => $dateRange) {
            if ($dateRange['start']->gt($currentDate)) {
                continue;
            }

            $weekStart = $dateRange['start']->copy()->startOfDay();
            $weekEnd = $dateRange['end']->copy()->endOfDay();

            $incidents = $allIncidents->filter(function ($incident) use ($weekStart, $weekEnd) {
                return $incident->incident_date->between($weekStart, $weekEnd);
            });

            $weekData[] = (object) [
                'week' => "W{$weekNumber}",
                'date_range' => $dateRange['start']->format('M j').' - '.$dateRange['end']->format('M j'),
                'incident_open' => $incidents->whereIn('incident_status', $openStatuses)->count(),
                'incident_closed' => $incidents->where('incident_status', IncidentStatus::Completed->value)->count(),
                'total' => $incidents->count(),
                'incidents' => $incidents->values(),
            ];
        }

        $this->cachedWeeklyData = $weekData;

        return $weekData;
    }

    protected function getIsoWeeksInYear(int $year): array
    {
        $weeks = [];
        $yearStart = \Carbon\Carbon::create($year, 1, 1)->startOfDay();

        $week1End = $yearStart->copy();
        while ($week1End->dayOfWeek !== 4) {
            $week1End->addDay();
        }

        $weeks[1] = [
            'start' => $yearStart->copy(),
            'end' => $week1End->copy(),
        ];

        $currentFriday = $week1End->copy()->addDay();
        while ($currentFriday->dayOfWeek !== 5) {
            $currentFriday->addDay();
        }

        $weekNumber = 2;
        while ($currentFriday->year === $year) {
            $weeks[$weekNumber] = [
                'start' => $currentFriday->copy(),
                'end' => $currentFriday->copy()->addDays(6),
            ];

            $currentFriday->addWeek();
            $weekNumber++;

            if ($weekNumber > 53) {
                break;
            }
        }

        return $weeks;
    }

    public function exportToExcel(): StreamedResponse
    {
        $weeklyData = $this->getWeeklyData();
        $totalOpen = collect($weeklyData)->sum('incident_open');
        $totalClosed = collect($weeklyData)->sum('incident_closed');
        $grandTotal = collect($weeklyData)->sum('total');

        $filename = 'weekly_report_'.$this->selectedYear.'_'.date('Y-m-d').'.xlsx';

        return Response::streamDownload(function () use ($weeklyData, $totalOpen, $totalClosed, $grandTotal) {
            if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                $this->exportWithPhpSpreadsheet($weeklyData, $totalOpen, $totalClosed, $grandTotal);
            } else {
                $this->exportAsCsv($weeklyData, $totalOpen, $totalClosed, $grandTotal);
            }
        }, $filename);
    }

    protected function exportWithPhpSpreadsheet(array $weeklyData, int $totalOpen, int $totalClosed, int $grandTotal): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'Weekly Incident Report - '.$this->selectedYear);
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A3', 'Summary');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->setCellValue('A4', 'Total Open');
        $sheet->setCellValue('B4', $totalOpen);
        $sheet->setCellValue('A5', 'Total Closed');
        $sheet->setCellValue('B5', $totalClosed);
        $sheet->setCellValue('A6', 'Grand Total');
        $sheet->setCellValue('B6', $grandTotal);

        $sheet->setCellValue('A8', 'Week');
        $sheet->setCellValue('B8', 'Date Range');
        $sheet->setCellValue('C8', 'Incident Open');
        $sheet->setCellValue('D8', 'Incident Closed');
        $sheet->setCellValue('E8', 'Total');

        $sheet->getStyle('A8:E8')->getFont()->setBold(true);

        $row = 9;
        foreach ($weeklyData as $data) {
            $sheet->setCellValue('A'.$row, $data->week);
            $sheet->setCellValue('B'.$row, $data->date_range);
            $sheet->setCellValue('C'.$row, $data->incident_open);
            $sheet->setCellValue('D'.$row, $data->incident_closed);
            $sheet->setCellValue('E'.$row, $data->total);
            $row++;
        }

        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
    }

    protected function exportAsCsv(array $weeklyData, int $totalOpen, int $totalClosed, int $grandTotal): void
    {
        $output = fopen('php://output', 'w');

        fprintf($output, "\xEF\xBB\xBF");

        fputcsv($output, ['Weekly Incident Report - '.$this->selectedYear]);

        fputcsv($output, ['']);
        fputcsv($output, ['Summary']);
        fputcsv($output, ['Total Open', $totalOpen]);
        fputcsv($output, ['Total Closed', $totalClosed]);
        fputcsv($output, ['Grand Total', $grandTotal]);

        fputcsv($output, ['']);
        fputcsv($output, ['Week', 'Date Range', 'Incident Open', 'Incident Closed', 'Total']);

        foreach ($weeklyData as $data) {
            fputcsv($output, [
                $data->week,
                $data->date_range,
                $data->incident_open,
                $data->incident_closed,
                $data->total,
            ]);
        }

        fclose($output);
    }
}
