<?php

namespace App\Filament\Pages;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Exports\AnalyticsExport;
use App\Models\ChartConfiguration;
use App\Services\Analytics\AnalyticsQueryService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

class AnalyticsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.analytics';

    public ?array $data = [];

    public ?array $chartData = null;

    public bool $chartVisible = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage incidents') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'metric' => 'count',
            'dimension' => 'monthly',
            'chart_type' => 'bar',
            'comparison_enabled' => false,
            'comparison_mode' => 'previous_period',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Saved Charts')
                    ->schema([
                        Select::make('template_id')
                            ->label('Load Saved Chart')
                            ->options(ChartConfiguration::where('user_id', auth()->id())->pluck('name', 'id'))
                            ->live()
                            ->searchable()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $this->loadTemplate($state, $set);
                                }
                            }),
                    ])
                    ->collapsible()
                    ->collapsed(true),

                Grid::make(3)->schema([
                    Section::make('Chart Configuration')
                        ->schema([
                            Select::make('metric')
                                ->label('Metric')
                                ->options(AnalyticsQueryService::METRICS)
                                ->required()
                                ->live()
                                ->default('count'),
                            Select::make('dimension')
                                ->label('Group By')
                                ->options(AnalyticsQueryService::DIMENSIONS)
                                ->required()
                                ->live()
                                ->default('monthly'),
                            Select::make('chart_type')
                                ->label('Chart Type')
                                ->options(AnalyticsQueryService::CHART_TYPES)
                                ->required()
                                ->default('bar'),
                        ])
                        ->columnSpan(1),

                    Section::make('Filters')
                        ->schema([
                            DatePicker::make('start_date')
                                ->default(now()->startOfYear()),
                            DatePicker::make('end_date')
                                ->default(now()->endOfYear()),
                            Select::make('severities')
                                ->label('Severity')
                                ->multiple()
                                ->options(Severity::options()),
                            Select::make('incident_types')
                                ->label('Incident Type')
                                ->multiple()
                                ->options(IncidentType::options()),
                            Select::make('statuses')
                                ->label('Status')
                                ->multiple()
                                ->options(IncidentStatus::options()),
                            Select::make('fund_statuses')
                                ->label('Fund Status')
                                ->multiple()
                                ->options(FundStatus::filterOptions()),
                        ])
                        ->columnSpan(1),

                    Section::make('Comparison')
                        ->schema([
                            Toggle::make('comparison_enabled')
                                ->label('Enable Comparison')
                                ->live()
                                ->default(false),
                            Select::make('comparison_mode')
                                ->label('Mode')
                                ->options([
                                    'previous_period' => 'Previous Period',
                                    'custom' => 'Custom Date Range',
                                ])
                                ->live()
                                ->visible(fn (callable $get) => $get('comparison_enabled'))
                                ->default('previous_period'),
                            DatePicker::make('comparison_start_date')
                                ->label('Comparison Start')
                                ->visible(fn (callable $get) => $get('comparison_enabled') && $get('comparison_mode') === 'custom'),
                            DatePicker::make('comparison_end_date')
                                ->label('Comparison End')
                                ->visible(fn (callable $get) => $get('comparison_enabled') && $get('comparison_mode') === 'custom'),
                        ])
                        ->columnSpan(1),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('deleteChart')
                ->label('Delete Chart')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->form([
                    Select::make('delete_template_id')
                        ->label('Select Chart to Delete')
                        ->options(ChartConfiguration::where('user_id', auth()->id())->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data) {
                    $template = ChartConfiguration::where('id', $data['delete_template_id'])
                        ->where('user_id', auth()->id())
                        ->first();
                    if ($template) {
                        $template->delete();
                        Notification::make()->title('Chart deleted')->success()->send();
                    }
                })
                ->requiresConfirmation(),
            Action::make('saveChart')
                ->label('Save Chart')
                ->form([
                    TextInput::make('chart_name')
                        ->label('Chart Name')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $formData = $this->form->getState();
                    ChartConfiguration::create([
                        'name' => $data['chart_name'],
                        'user_id' => auth()->id(),
                        'metric' => $formData['metric'],
                        'dimension' => $formData['dimension'],
                        'chart_type' => $formData['chart_type'],
                        'filters' => [
                            'start_date' => $formData['start_date'] ?? null,
                            'end_date' => $formData['end_date'] ?? null,
                            'severities' => $formData['severities'] ?? [],
                            'incident_types' => $formData['incident_types'] ?? [],
                            'statuses' => $formData['statuses'] ?? [],
                            'fund_statuses' => $formData['fund_statuses'] ?? [],
                        ],
                        'comparison' => ($formData['comparison_enabled'] ?? false) ? [
                            'mode' => $formData['comparison_mode'] ?? 'previous_period',
                            'start_date' => $formData['comparison_start_date'] ?? null,
                            'end_date' => $formData['comparison_end_date'] ?? null,
                        ] : null,
                    ]);
                    Notification::make()->title('Chart saved successfully')->success()->send();
                }),
            Action::make('export')
                ->label('Export Data')
                ->color('success')
                ->visible(fn () => $this->chartVisible)
                ->action(fn () => $this->exportData()),
        ];
    }

    public function generateChart(): void
    {
        $data = $this->form->getState();

        $service = app(AnalyticsQueryService::class);
        $comparison = null;

        if ($data['comparison_enabled'] ?? false) {
            $comparison = [
                'enabled' => true,
                'mode' => $data['comparison_mode'] ?? 'previous_period',
                'start_date' => $data['comparison_start_date'] ?? null,
                'end_date' => $data['comparison_end_date'] ?? null,
                'primary_label' => 'Current Period',
                'secondary_label' => 'Comparison Period',
            ];
        }

        $this->chartData = $service->build(
            $data['metric'],
            $data['dimension'],
            $data['chart_type'],
            $data,
            $comparison
        );
        $this->chartVisible = true;

        $this->js('$nextTick(() => { window.dispatchEvent(new CustomEvent("analytics-chart-updated", { detail: ' . json_encode(['chartData' => $this->chartData, 'chartType' => $data['chart_type']]) . ' })) })');
    }

    private function loadTemplate(string $templateId, callable $set): void
    {
        $template = ChartConfiguration::find($templateId);
        if (! $template) {
            return;
        }

        $set('metric', $template->metric);
        $set('dimension', $template->dimension);
        $set('chart_type', $template->chart_type);
        $set('start_date', $template->filters['start_date'] ?? null);
        $set('end_date', $template->filters['end_date'] ?? null);
        $set('severities', $template->filters['severities'] ?? []);
        $set('incident_types', $template->filters['incident_types'] ?? []);
        $set('statuses', $template->filters['statuses'] ?? []);
        $set('fund_statuses', $template->filters['fund_statuses'] ?? []);
        $set('comparison_enabled', ! empty($template->comparison));
        $set('comparison_mode', $template->comparison['mode'] ?? 'previous_period');
        $set('comparison_start_date', $template->comparison['start_date'] ?? null);
        $set('comparison_end_date', $template->comparison['end_date'] ?? null);

        $this->generateChart();
    }

    public function exportData()
    {
        if (! $this->chartData) {
            return;
        }

        $formData = $this->form->getState();

        return Excel::download(
            new AnalyticsExport(
                $this->chartData['raw_data'],
                AnalyticsQueryService::metricLabel($formData['metric']),
                AnalyticsQueryService::dimensionLabel($formData['dimension']),
            ),
            'analytics_'.$formData['metric'].'_by_'.$formData['dimension'].'.xlsx'
        );
    }
}
