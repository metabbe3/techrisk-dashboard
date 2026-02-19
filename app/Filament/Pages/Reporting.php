<?php

namespace App\Filament\Pages;

use App\Exports\IncidentsExport;
use App\Models\Incident;
use App\Models\ReportTemplate;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

class Reporting extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.reporting';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public ?array $data = [];

    public $incidents = [];

    public $metrics = [];

    public function mount(): void
    {
        $this->form->fill([
            'metrics' => ['total_incidents', 'avg_mttr', 'avg_mtbf'],
            'columns' => ['id', 'title', 'severity', 'incident_date', 'mttr', 'mtbf'],
        ]);
    }

    private function getColumns(): array
    {
        return [
            'Incident' => [
                'id' => 'ID',
                'title' => 'Title',
                'summary' => 'Summary',
                'root_cause' => 'Root Cause',
                'severity' => 'Severity',
                'incident_date' => 'Incident Date',
                'discovered_at' => 'Discovered At',
                'stop_bleeding_at' => 'Stop Bleeding At',
                'entry_date_tech_risk' => 'Entry Date to Tech Risk',
                'reported_by' => 'Reported By',
                'involved_third_party' => 'Involved Third Party',
                'potential_fund_loss' => 'Potential Fund Loss',
                'fund_loss' => 'Fund Loss',
                'people_caused' => 'People Caused',
                'checker' => 'Checker',
                'maker' => 'Maker',
                'mttr' => 'MTTR',
                'mtbf' => 'MTBF',
            ],
            'PIC (User)' => [
                'pic.name' => 'Name',
                'pic.email' => 'Email',
            ],
            'Incident Type' => [
                'incidentType.name' => 'Name',
            ],
            'Latest Status Update' => [
                'latestStatusUpdate.status' => 'Status',
                'latestStatusUpdate.update_date' => 'Update Date',
            ],
        ];
    }

    private function getColumnsForForm(): array
    {
        $columns = [];
        foreach ($this->getColumns() as $group => $groupColumns) {
            foreach ($groupColumns as $key => $label) {
                $columns[$key] = "$group: $label";
            }
        }

        return $columns;
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Templates')
                    ->schema([
                        Select::make('template_id')
                            ->label('Load Template')
                            ->options(ReportTemplate::pluck('name', 'id'))
                            ->live()
                            ->searchable()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $template = ReportTemplate::find($state);
                                    if ($template) {
                                        $set('start_date', $template->filters['start_date'] ?? null);
                                        $set('end_date', $template->filters['end_date'] ?? null);
                                        $set('incident_types', $template->filters['incident_types'] ?? []);
                                        $set('statuses', $template->filters['statuses'] ?? []);
                                        $set('severities', $template->filters['severities'] ?? []);
                                        $set('columns', $template->columns ?? []);
                                        $set('metrics', $template->metrics ?? []);
                                    }
                                }
                            }),
                    ]),
                Grid::make(2)->schema([
                    Section::make('Filters')
                        ->schema([
                            DatePicker::make('start_date'),
                            DatePicker::make('end_date'),
                            Select::make('incident_types')
                                ->multiple()
                                ->options([
                                    'Tech' => 'Tech',
                                    'Non-tech' => 'Non-tech',
                                ])
                                ->live(),
                            Select::make('statuses')
                                ->multiple()
                                ->options([
                                    'Open' => 'Open',
                                    'In progress' => 'In progress',
                                    'Finalization' => 'Finalization',
                                    'Completed' => 'Completed',
                                ]),
                            Select::make('severities')
                                ->multiple()
                                ->options([
                                    'p1' => 'P1',
                                    'p2' => 'P2',
                                    'p3' => 'P3',
                                    'p4' => 'P4',
                                    'Non Incident' => 'Non Incident',
                                    'X1' => 'X1',
                                    'X2' => 'X2',
                                    'X3' => 'X3',
                                    'X4' => 'X4',
                                ]),
                        ])->columnSpan(1),
                    Section::make('Report Content')
                        ->schema([
                            CheckboxList::make('metrics')
                                ->label('Metrics to Calculate')
                                ->options([
                                    'total_incidents' => 'Total Incidents',
                                    'avg_mttr' => 'Average MTTR',
                                    'avg_mtbf' => 'Average MTBF',
                                ]),
                            Select::make('columns')
                                ->label('Columns to Include')
                                ->multiple()
                                ->searchable()
                                ->options($this->getColumnsForForm()),
                        ])->columnSpan(1),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveTemplate')
                ->label('Save as Template')
                ->form([
                    TextInput::make('new_template_name')
                        ->label('Template Name')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $formData = $this->form->getState();

                    ReportTemplate::create([
                        'name' => $data['new_template_name'],
                        'user_id' => auth()->id(),
                        'filters' => [
                            'start_date' => $formData['start_date'],
                            'end_date' => $formData['end_date'],
                            'incident_types' => $formData['incident_types'],
                            'statuses' => $formData['statuses'],
                            'severities' => $formData['severities'],
                        ],
                        'columns' => $formData['columns'],
                        'metrics' => $formData['metrics'],
                    ]);

                    Notification::make()
                        ->title('Template saved successfully')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function generateReport()
    {
        $data = $this->form->getState();

        $query = Incident::query();

        if ($data['start_date']) {
            $query->where('incident_date', '>=', Carbon::parse($data['start_date']));
        }

        if ($data['end_date']) {
            $query->where('incident_date', '<=', Carbon::parse($data['end_date']));
        }

        if (! empty($data['incident_types'])) {
            $query->whereIn('incident_type', $data['incident_types']);
        }

        if (! empty($data['statuses'])) {
            $query->whereHas('latestStatusUpdate', function ($q) use ($data) {
                $q->whereIn('status', $data['statuses']);
            });
        }

        if (! empty($data['severities'])) {
            $query->whereIn('severity', $data['severities']);
        }

        // Calculate metrics at database level before loading incidents
        $metrics = [];
        if (in_array('total_incidents', $data['metrics'] ?? [])) {
            $metrics['total_incidents'] = (clone $query)->count();
        }
        if (in_array('avg_mttr', $data['metrics'] ?? [])) {
            $metrics['avg_mttr'] = (clone $query)->avg('mttr');
        }
        if (in_array('avg_mtbf', $data['metrics'] ?? [])) {
            // Calculate MTBF correctly: Total Time Period / Number of Incidents
            $queryForMtbf = clone $query;
            $totalIncidents = $queryForMtbf->count();
            $avgMtbf = 0;

            if ($totalIncidents > 0) {
                $minDate = $queryForMtbf->min('incident_date');
                $maxDate = $queryForMtbf->max('incident_date');

                if ($minDate && $maxDate) {
                    $minDate = Carbon::parse($minDate)->startOfDay();
                    $maxDate = Carbon::parse($maxDate)->startOfDay();
                    $totalDays = $minDate->diffInDays($maxDate);
                    $avgMtbf = $totalIncidents > 1 ? round($totalDays / ($totalIncidents - 1), 3) : 0;
                }
            }
            $metrics['avg_mtbf'] = $avgMtbf;
        }
        $this->metrics = $metrics;

        // Determine which relations need to be loaded
        $relations = [];
        if (! empty($data['columns'])) {
            foreach ($data['columns'] as $column) {
                if (str_contains($column, '.')) {
                    $relations[] = explode('.', $column)[0];
                }
            }
        }

        // Load incidents with eager loaded relations
        $this->incidents = $query->with(array_unique($relations))->get();
    }

    public function getColumnsFlattened(): array
    {
        return array_merge(...array_values($this->getColumns()));
    }

    public function export()
    {
        $data = $this->form->getState();
        $columns = $data['columns'] ?? [];
        if (empty($columns)) {
            // Handle case where no columns are selected
            return;
        }
        $allColumns = $this->getColumnsFlattened();
        $headings = array_intersect_key($allColumns, array_flip($columns));

        return Excel::download(new IncidentsExport($this->incidents, $this->metrics, $headings), 'incidents.xlsx');
    }
}
