<?php

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Exports\IncidentTableExport;
use App\Exports\MultiSheetIncidentsExport;
use App\Filament\Resources\IncidentResource;
use Filament\Actions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;

    private static function getColumnOptions(): array
    {
        return [
            'no' => 'ID', 'title' => 'Title', 'mttr' => 'MTTR (mins)', 'mtbf' => 'MTBF (days)',
            'severity' => 'Severity', 'incident_status' => 'Incident Status', 'incident_date' => 'Incident Date',
            'potential_fund_loss' => 'Potential Fund Loss', 'recovered_fund' => 'Recovered Fund', 'fund_loss' => 'Actual Fund Loss',
            'classification' => 'Classification', 'incident_type' => 'Incident Type', 'entry_date_tech_risk' => 'Entry Date Tech Risk',
            'discovered_at' => 'Discovered At', 'stop_bleeding_at' => 'Stop Bleeding At', 'glitch_flag' => 'Glitch Flag',
            'incident_source' => 'Incident Source', 'incident_category' => 'Incident Category', 'fund_status' => 'Fund Status',
            'loss_taken_by' => 'Loss Taken By', 'pic' => 'PIC', 'reported_by' => 'Reported By',
            'third_party_client' => '3rd Party Client', 'goc_upload' => 'GoC Upload', 'teams_upload' => 'Teams Upload',
            'doc_signed' => 'Doc Signed', 'risk_incident_form_cfm' => 'Risk Incident Form CFM', 'summary' => 'Summary',
            'remark' => 'Remark', 'root_cause' => 'Root Cause', 'improvements' => 'Improvements',
            'evidence' => 'Evidence', 'evidence_link' => 'Evidence Link', 'action_improvement_tracking' => 'Action Improvement Tracking',
            'investigation_pic_status' => 'Investigation PIC Status',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('ai_search')
                ->label('AI Search')
                ->icon('heroicon-o-sparkles')
                ->color('violet')
                ->extraAttributes([
                    'class' => 'header-btn-ai',
                ])
                ->modalHeading('AI-Powered Search')
                ->modalDescription('Describe what you are looking for in natural language.')
                ->modalSubmitActionLabel('Apply Filters')
                ->form(function () {
                    $aiService = app(\App\Services\Ai\AiTextService::class);
                    $models = $aiService->getAvailableModels();
                    $defaultModel = \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL'));

                    return [
                        Select::make('ai_model')
                            ->label('AI Model')
                            ->options($models)
                            ->default($defaultModel)
                            ->searchable()
                            ->visible(count($models) > 1),
                        \Filament\Forms\Components\TextInput::make('nl_query')
                            ->label('Search query')
                            ->placeholder('e.g. "show me all P1 fund loss incidents from Q1 related to payment gateway"')
                            ->required()
                            ->minLength(3)
                            ->maxLength(500)
                            ->live()
                            ->helperText('The AI will convert your query into table filters.'),
                    ];
                })
                ->action(function (array $data) {
                    $model = $data['ai_model'] ?? null;
                    $this->applyAiSearch($data['nl_query'], $model);
                }),
            Actions\Action::make('export')
                ->label('Export')
                ->icon('heroicon-o-document-arrow-down')
                ->color('info')
                ->extraAttributes([
                    'class' => 'header-btn-export',
                ])
                ->form(function () {
                    $columnOptions = self::getColumnOptions();

                    return [
                        Checkbox::make('export_all_tabs')
                            ->label('Export all tabs as separate sheets (XLSX only)')
                            ->live(),
                        Select::make('format')
                            ->label('Format')
                            ->options(['xlsx' => 'XLSX', 'csv' => 'CSV'])
                            ->required()
                            ->visible(fn ($get) => ! $get('export_all_tabs')),
                        CheckboxList::make('columns')
                            ->label('Columns to Export')
                            ->options($columnOptions)
                            ->default(array_keys($columnOptions))
                            ->columns(3)
                            ->required(),
                    ];
                })
                ->action(function (array $data) {
                    $query = $this->getFilteredTableQuery()->clone();
                    $selectedColumns = $data['columns'];
                    $columnOptions = self::getColumnOptions();
                    $headings = array_values(array_intersect_key($columnOptions, array_flip($selectedColumns)));

                    if ($data['export_all_tabs']) {
                        return Excel::download(
                            new MultiSheetIncidentsExport($query, $headings, $selectedColumns),
                            'incidents-all-tabs-'.now()->format('Y-m-d').'.xlsx'
                        );
                    }

                    $format = $data['format'];

                    $query->orderBy('incident_date', 'asc');

                    $totalCases = $query->count();

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

                    $stats = [
                        'totalCases' => $totalCases,
                        'avgMttr' => round($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr') ?? 0, 2),
                        'avgMtbf' => $avgMtbf,
                        'totalPotentialFundLoss' => $query->sum('potential_fund_loss'),
                        'totalFundLoss' => $query->sum('fund_loss'),
                        'totalRecoveredFund' => $query->sum('recovered_fund'),
                    ];

                    $incidents = $query->get();

                    return Excel::download(
                        new IncidentTableExport($incidents, $stats, $headings, $selectedColumns),
                        'incidents-'.now()->format('Y-m-d').'.'.$format
                    );
                }),
            Actions\Action::make('recalculate_metrics')
                ->label('Recalculate')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->outlined()
                ->extraAttributes([
                    'class' => 'header-btn-recalc',
                ])
                ->requiresConfirmation()
                ->modalHeading('Recalculate MTBF & MTTR')
                ->modalDescription('This will recalculate all MTBF and MTTR values for every incident. This may take a few seconds.')
                ->visible(fn (): bool => auth()->user()->can('manage incidents'))
                ->action(function () {
                    \Illuminate\Support\Facades\Artisan::call('incidents:recalculate-metrics');
                    \Filament\Notifications\Notification::make()
                        ->title('Metrics recalculated')
                        ->body('All MTBF and MTTR values have been updated.')
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make()
                ->label('New Incident')
                ->icon('heroicon-o-plus')
                ->extraAttributes([
                    'class' => 'header-btn-create',
                ])
                ->visible(fn (): bool => auth()->user()->can('manage incidents')),
        ];
    }

    public function getTableQuery(): Builder
    {
        app()->instance('activeTab', $this->activeTab ?? 'All Cases');

        return parent::getTableQuery();
    }

    public function getTabs(): array
    {
        return [
            'All Cases' => Tab::make(),
            'On Going' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('incident_status', '!=', IncidentStatus::Completed->value)),
            'Completed Cases' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('incident_status', IncidentStatus::Completed->value)),
            'Recovered Cases' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('recovered_fund', '>', 0)),
            'P4 Incidents' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('severity', Severity::P4->value)),
            'Non-Tech Incidents' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('incident_type', IncidentType::NonTech->value)),
            'Fund Loss' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('fund_status', FundStatus::ConfirmedLoss->value)),
            'Potential Recovery' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('fund_status', FundStatus::PotentialRecovery->value)),
            'Fully Recovered' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('fund_status', FundStatus::FullyRecovered->value)),
            'Non Tech Loss' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('fund_status', FundStatus::NonTechLoss->value)),
            'Non Fund Loss' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('fund_status', FundStatus::NonFundLoss->value)),
            'Non Incident' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('severity', Severity::NonIncident->value)),
        ];
    }

    public function getTableFooter(): ?View
    {
        // Clone the query to avoid affecting the main table query
        $query = $this->getFilteredTableQuery()->clone();

        $totalCases = $query->count();

        // Calculate MTBF correctly: Total Time Period / Number of Incidents
        // Only include eligible severities from MTBF calculation
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

        $stats = [
            'totalCases' => $totalCases,
            'avgMttrMins' => round($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr') ?? 0, 2),
            'avgMttrDays' => round(abs($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '<', 0)->avg('mttr') ?? 0), 2),
            'avgMtbf' => $avgMtbf,
            'totalPotentialFundLoss' => $query->sum('potential_fund_loss'),
            'totalFundLoss' => $query->sum('fund_loss'),
            'totalRecoveredFund' => $query->sum('recovered_fund'),
        ];

        return view('livewire.incident-stats-footer', ['stats' => $stats]);
    }

    public function applyAiSearch(string $query, ?string $model = null): void
    {
        try {
            $aiService = app(\App\Services\Ai\AiTextService::class);
            $result = $aiService->parseNaturalLanguageQuery($query, $model);
            $filters = $result['filters'] ?? [];

            if (empty($filters)) {
                \Filament\Notifications\Notification::make()
                    ->warning()
                    ->title('AI Search')
                    ->body($result['explanation'] ?? 'Could not understand the query. Try rephrasing.')
                    ->send();

                return;
            }

            $tableFilters = [];

            // Enum filters
            if (! empty($filters['severity'])) {
                $tableFilters['severity'] = ['values' => $filters['severity']];
            }
            if (! empty($filters['incident_status'])) {
                $tableFilters['incident_status'] = ['values' => $filters['incident_status']];
            }
            if (! empty($filters['fund_status'])) {
                $tableFilters['fund_status'] = ['value' => $filters['fund_status'][0] ?? $filters['fund_status']];
            }
            if (! empty($filters['incident_type'])) {
                $tableFilters['incident_type'] = ['values' => $filters['incident_type']];
            }
            if (! empty($filters['classification'])) {
                $tableFilters['classification'] = ['values' => $filters['classification']];
            }
            if (! empty($filters['incident_source'])) {
                $tableFilters['incident_source'] = ['values' => (array) $filters['incident_source']];
            }

            // Date range — clear quick_period to avoid conflicting constraints
            if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
                $tableFilters['custom_date_range'] = array_filter([
                    'from' => $filters['date_from'] ?? null,
                    'until' => $filters['date_to'] ?? null,
                ]);
                $tableFilters['quick_period'] = ['value' => 'all'];
            }

            // Labels
            if (! empty($filters['labels'])) {
                $tableFilters['labels'] = ['values' => (array) $filters['labels']];
            }

            // PIC — resolve name to ID
            if (! empty($filters['pic_name'])) {
                $picIds = \App\Models\User::where('name', 'like', '%'.$filters['pic_name'].'%')->pluck('id')->toArray();
                if (! empty($picIds)) {
                    $tableFilters['pic_id'] = ['values' => $picIds];
                }
            }

            // Glitch flag
            if (isset($filters['glitch_flag']) && $filters['glitch_flag'] !== null) {
                $tableFilters['glitch_flag'] = ['value' => $filters['glitch_flag'] ? '1' : '0'];
            }

            // JSON category filters
            if (! empty($filters['business_category'])) {
                $tableFilters['business_category'] = ['values' => (array) $filters['business_category']];
            }
            if (! empty($filters['root_cause_category'])) {
                $tableFilters['root_cause_category'] = ['values' => (array) $filters['root_cause_category']];
            }
            if (! empty($filters['responsible_team'])) {
                $tableFilters['responsible_team'] = ['values' => (array) $filters['responsible_team']];
            }

            // Fund loss range
            $fundMin = $filters['fund_loss_min'] ?? null;
            $fundMax = $filters['fund_loss_max'] ?? null;
            if ($fundMin !== null || $fundMax !== null) {
                $tableFilters['fund_loss_range'] = array_filter([
                    'min' => $fundMin,
                    'max' => $fundMax,
                ]);
            }

            // Missing root cause
            if (isset($filters['has_root_cause']) && $filters['has_root_cause'] === false) {
                $tableFilters['missing_root_cause'] = ['enabled' => true];
            }

            // Content search (full-text across body fields)
            if (! empty($filters['content_search'])) {
                $tableFilters['content_search'] = ['query' => $filters['content_search']];
            }

            // Title/ID search
            if (! empty($filters['search_keywords'])) {
                $this->tableSearch = implode(' ', $filters['search_keywords']);
            }

            if (! empty($tableFilters) || ! empty($filters['search_keywords'])) {
                $this->tableFilters = $tableFilters;
                $this->resetPage();

                \Filament\Notifications\Notification::make()
                    ->success()
                    ->title('AI Search')
                    ->body($result['explanation'] ?? 'Filters applied.')
                    ->send();
            }
        } catch (\Throwable $e) {
            \Filament\Notifications\Notification::make()
                ->danger()
                ->title('AI Search Error')
                ->body($e->getMessage())
                ->send();
        }
    }

}
