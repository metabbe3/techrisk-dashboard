<?php

namespace App\Filament\Pages;

use App\Models\Incident;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class WeeklyReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationLabel = 'Weekly Report';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.weekly-report';

    protected static bool $isDiscovered = true;

    // Filter state
    public ?int $selectedYear = null;

    public function mount(): void
    {
        $this->selectedYear = (int) date('Y');
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
                    ->afterStateUpdated(fn () => $this->resetTable()),
            ])
            ->statePath('selectedYear');
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

    // Get weekly incident statistics
    public function getWeeklyData(): array
    {
        $currentDate = now();
        $weekData = [];

        // Get all ISO weeks for the selected year
        $weeks = $this->getIsoWeeksInYear($this->selectedYear);

        foreach ($weeks as $weekNumber => $dateRange) {
            // Skip future weeks
            if ($dateRange['start']->gt($currentDate)) {
                continue;
            }

            // Query incidents for this week
            $weekStart = $dateRange['start']->copy()->startOfDay();
            $weekEnd = $dateRange['end']->copy()->endOfDay();

            $incidents = Incident::where('classification', 'Incident')
                ->whereBetween('incident_date', [$weekStart, $weekEnd])
                ->get();

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

    // Get ISO weeks for a given year
    protected function getIsoWeeksInYear(int $year): array
    {
        $weeks = [];
        $date = \Carbon\Carbon::create($year, 1, 1);

        // Find first Monday of the year
        while ($date->dayOfWeek !== 1) {
            $date->addDay();
        }

        $weekNumber = 1;

        while ($date->year <= $year) {
            $weekStart = $date->copy();
            $weekEnd = $date->copy()->addDays(6);

            $weeks[$weekNumber] = [
                'start' => $weekStart,
                'end' => $weekEnd,
            ];

            $date->addWeek();
            $weekNumber++;

            // Safety break
            if ($weekNumber > 53) {
                break;
            }
        }

        return $weeks;
    }

    // Table definition
    public function table(Table $table): Table
    {
        return $table
            ->query(Incident::query()->where('id', '<', 0)) // Dummy query for custom data
            ->columns([
                TextColumn::make('week')
                    ->label('Week')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('date_range')
                    ->label('Date Range')
                    ->sortable(),
                TextColumn::make('incident_open')
                    ->label('Incident Open')
                    ->sortable()
                    ->badge()
                    ->color('warning'),
                TextColumn::make('incident_closed')
                    ->label('Incident Closed')
                    ->sortable()
                    ->badge()
                    ->color('success'),
                TextColumn::make('total')
                    ->label('Total')
                    ->sortable()
                    ->badge()
                    ->color('primary'),
            ])
            ->defaultSort('week', 'asc')
            ->paginated([10, 25, 50, 100]);
    }

    // Override to return our custom data
    public function getTableRecords(): array
    {
        return $this->getWeeklyData();
    }

    // Reset table when year changes
    protected function resetTable(): void
    {
        $this->table->resetPage();
    }
}
