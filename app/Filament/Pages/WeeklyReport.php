<?php

namespace App\Filament\Pages;

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

    /**
     * Determine if the user can access this page.
     * Allows both admin and regular users with 'access dashboard' permission.
     */
    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('access dashboard');
    }

    // Filter state
    public ?int $selectedYear = null;

    // Pagination state
    public int $perPage = 10;

    public int $currentPage = 1;

    public function mount(): void
    {
        $this->selectedYear = (int) date('Y');
        $this->currentPage = request()->get('page', 1);
    }

    // Year filter form
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('selectedYear')
                    ->label('Year')
                    ->options($this->getYearOptions())
                    ->default($this->selectedYear)
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        $this->selectedYear = $state;
                        $this->currentPage = 1; // Reset to first page when year changes
                    }),
            ]);
    }

    // Get available years (current year and past 5 years)
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

    // Get weekly incident statistics with pagination
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

    // Get weekly incident statistics
    public function getWeeklyData(): array
    {
        $currentDate = now();
        $weekData = [];

        // Use selected year or default to current year
        $year = $this->selectedYear ?? (int) date('Y');

        // Get all ISO weeks for the selected year
        $weeks = $this->getIsoWeeksInYear($year);

        // OPTIMIZED: Load all incidents for the year in a single query
        // Exclude 'Potential recovery' fund status from weekly report
        $allIncidents = Incident::where('classification', 'Incident')
            ->whereYear('incident_date', $year)
            ->where(fn ($query) => $query->whereNull('fund_status')
                ->orWhere('fund_status', '!=', 'Potential recovery'))
            ->get();

        foreach ($weeks as $weekNumber => $dateRange) {
            // Skip future weeks
            if ($dateRange['start']->gt($currentDate)) {
                continue;
            }

            // Filter from already-loaded collection
            $weekStart = $dateRange['start']->copy()->startOfDay();
            $weekEnd = $dateRange['end']->copy()->endOfDay();

            $incidents = $allIncidents->filter(function ($incident) use ($weekStart, $weekEnd) {
                return $incident->incident_date->between($weekStart, $weekEnd);
            });

            $openCount = $incidents->whereIn('incident_status', ['Open', 'In progress', 'Finalization'])->count();
            $closedCount = $incidents->where('incident_status', 'Completed')->count();
            $totalCount = $incidents->count();

            $weekData[] = (object) [
                'week' => "W{$weekNumber}",
                'date_range' => $dateRange['start']->format('M j').' - '.$dateRange['end']->format('M j'),
                'incident_open' => $openCount,
                'incident_closed' => $closedCount,
                'total' => $totalCount,
            ];
        }

        return $weekData;
    }

    // Get custom Friday-Thursday weeks for a given year
    // Week 1: January 1st only (or until first Thursday if Jan 1 is before Thursday)
    // Week 2 onwards: Friday to Thursday
    protected function getIsoWeeksInYear(int $year): array
    {
        $weeks = [];
        $yearStart = \Carbon\Carbon::create($year, 1, 1)->startOfDay();

        // Week 1: Always starts from January 1st
        // Find the first Thursday on or after Jan 1
        $week1End = $yearStart->copy();
        while ($week1End->dayOfWeek !== 4) { // 4 = Thursday
            $week1End->addDay();
        }

        $weeks[1] = [
            'start' => $yearStart->copy(),
            'end' => $week1End->copy(),
        ];

        // Find the first Friday after Week 1 ends
        $currentFriday = $week1End->copy()->addDay(); // Day after first Thursday
        while ($currentFriday->dayOfWeek !== 5) { // 5 = Friday
            $currentFriday->addDay();
        }

        // Week 2 onwards: Friday to Thursday (7 days each)
        $weekNumber = 2;
        while ($currentFriday->year === $year) {
            $weekStart = $currentFriday->copy();
            $weekEnd = $currentFriday->copy()->addDays(6); // Thursday

            $weeks[$weekNumber] = [
                'start' => $weekStart,
                'end' => $weekEnd,
            ];

            // Move to next Friday
            $currentFriday->addWeek();
            $weekNumber++;

            // Safety break
            if ($weekNumber > 53) {
                break;
            }
        }

        return $weeks;
    }

    // Get summary statistics
    public function getSummaryStats(): array
    {
        $weeklyData = $this->getWeeklyData();
        $totalOpen = collect($weeklyData)->sum('incident_open');
        $totalClosed = collect($weeklyData)->sum('incident_closed');
        $grandTotal = collect($weeklyData)->sum('total');

        return [
            'total_open' => $totalOpen,
            'total_closed' => $totalClosed,
            'grand_total' => $grandTotal,
        ];
    }

    // Export to Excel
    public function exportToExcel(): StreamedResponse
    {
        $weeklyData = $this->getWeeklyData();
        $totalOpen = collect($weeklyData)->sum('incident_open');
        $totalClosed = collect($weeklyData)->sum('incident_closed');
        $grandTotal = collect($weeklyData)->sum('total');

        $filename = 'weekly_report_'.$this->selectedYear.'_'.date('Y-m-d').'.xlsx';

        return Response::streamDownload(function () use ($weeklyData, $totalOpen, $totalClosed, $grandTotal) {
            // Use PhpSpreadsheet if available, otherwise fallback to CSV with XLS extension
            if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                $this->exportWithPhpSpreadsheet($weeklyData, $totalOpen, $totalClosed, $grandTotal);
            } else {
                $this->exportAsCsv($weeklyData, $totalOpen, $totalClosed, $grandTotal);
            }
        }, $filename);
    }

    // Export using PhpSpreadsheet
    protected function exportWithPhpSpreadsheet(array $weeklyData, int $totalOpen, int $totalClosed, int $grandTotal): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Title
        $sheet->setCellValue('A1', 'Weekly Incident Report - '.$this->selectedYear);
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Summary stats
        $sheet->setCellValue('A3', 'Summary');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->setCellValue('A4', 'Total Open');
        $sheet->setCellValue('B4', $totalOpen);
        $sheet->setCellValue('A5', 'Total Closed');
        $sheet->setCellValue('B5', $totalClosed);
        $sheet->setCellValue('A6', 'Grand Total');
        $sheet->setCellValue('B6', $grandTotal);

        // Headers
        $sheet->setCellValue('A8', 'Week');
        $sheet->setCellValue('B8', 'Date Range');
        $sheet->setCellValue('C8', 'Incident Open');
        $sheet->setCellValue('D8', 'Incident Closed');
        $sheet->setCellValue('E8', 'Total');

        // Make headers bold
        $sheet->getStyle('A8:E8')->getFont()->setBold(true);

        // Data rows
        $row = 9;
        foreach ($weeklyData as $data) {
            $sheet->setCellValue('A'.$row, $data->week);
            $sheet->setCellValue('B'.$row, $data->date_range);
            $sheet->setCellValue('C'.$row, $data->incident_open);
            $sheet->setCellValue('D'.$row, $data->incident_closed);
            $sheet->setCellValue('E'.$row, $data->total);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
    }

    // Export as CSV (fallback)
    protected function exportAsCsv(array $weeklyData, int $totalOpen, int $totalClosed, int $grandTotal): void
    {
        $output = fopen('php://output', 'w');

        // Add BOM for UTF-8
        fprintf($output, "\xEF\xBB\xBF");

        // Title
        fputcsv($output, ['Weekly Incident Report - '.$this->selectedYear]);

        // Summary
        fputcsv($output, ['']);
        fputcsv($output, ['Summary']);
        fputcsv($output, ['Total Open', $totalOpen]);
        fputcsv($output, ['Total Closed', $totalClosed]);
        fputcsv($output, ['Grand Total', $grandTotal]);

        // Headers
        fputcsv($output, ['']);
        fputcsv($output, ['Week', 'Date Range', 'Incident Open', 'Incident Closed', 'Total']);

        // Data
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
