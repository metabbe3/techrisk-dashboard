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
            'business_category' => 'Business Category',
            'root_cause_category' => 'Root Cause Category',
            'responsible_team' => 'Responsible Team',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('board_view')
                ->label('Board View')
                ->icon('heroicon-o-view-columns')
                ->color('gray')
                ->outlined()
                ->url(fn () => IncidentResource::getUrl('board'))
                ->extraAttributes(['class' => 'header-btn-board']),
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
                    $models = $aiService->getModelsForPicker();
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
                    $severityOptions = collect(Severity::cases())->mapWithKeys(fn ($s) => [$s->value => $s->value])->all();
                    $statusOptions = collect(IncidentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->value])->all();
                    $typeOptions = collect(IncidentType::cases())->mapWithKeys(fn ($s) => [$s->value => $s->value])->all();
                    $fundOptions = collect(FundStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->value])->all();
                    $picOptions = \App\Models\User::orderBy('name')->pluck('name', 'id')->all();
                    $businessOptions = Incident::distinct()->get('business_category')
                        ->pluck('business_category')->filter()->flatMap(fn ($a) => $a)->unique()->sort()->values()->all();
                    $rootCauseOptions = Incident::distinct()->get('root_cause_category')
                        ->pluck('root_cause_category')->filter()->flatMap(fn ($a) => $a)->unique()->sort()->values()->all();

                    return [
                        \Filament\Forms\Components\Radio::make('preset')
                            ->label('What do you want to export?')
                            ->options([
                                'executive' => '📊 Executive Report — KPI cards + 4 charts + data (recommended)',
                                'group_by' => '🗂️ Group By — one sheet per category/division + MTTR/MTBF summary',
                                'all_tabs' => '📚 All Tabs — one sheet per tab (XLSX)',
                                'custom' => '⚙️ Custom — pick columns & format',
                            ])
                            ->default('executive')
                            ->live()
                            ->descriptions([
                                'executive' => 'Summary sheet with KPIs and native Excel charts, plus a clean data sheet.',
                                'all_tabs' => '16 sheets mirroring the table tabs, incl. Issues metrics.',
                                'group_by' => 'Pick a dimension: business category, root cause, division, PIC, severity. Each value gets its own sheet (multi-category incidents appear in each). Summary sheet has per-group MTTR/MTBF.',
                                'custom' => 'Full control: choose columns, XLSX or CSV.',
                            ]),

                        \Filament\Forms\Components\Section::make('Filter export (optional — applies to every preset)')
                            ->description('Leave empty to export the filtered set you see in the table right now.')
                            ->collapsed()
                            ->schema([
                                Select::make('f_severity')
                                    ->label('Severity')
                                    ->options($severityOptions)
                                    ->multiple()
                                    ->placeholder('All severities'),
                                Select::make('f_status')
                                    ->label('Incident Status')
                                    ->options($statusOptions)
                                    ->multiple()
                                    ->placeholder('All statuses'),
                                Select::make('f_incident_type')
                                    ->label('Incident Type')
                                    ->options($typeOptions)
                                    ->multiple()
                                    ->placeholder('All types'),
                                Select::make('f_fund_status')
                                    ->label('Fund Status')
                                    ->options($fundOptions)
                                    ->multiple()
                                    ->placeholder('All fund statuses'),
                                Select::make('f_pic')
                                    ->label('PIC')
                                    ->options($picOptions)
                                    ->multiple()
                                    ->searchable()
                                    ->placeholder('All PICs'),
                                Select::make('f_business_category')
                                    ->label('Business Category')
                                    ->options(array_combine($businessOptions, $businessOptions))
                                    ->multiple()
                                    ->placeholder('All categories'),
                                Select::make('f_root_cause')
                                    ->label('Root Cause Category')
                                    ->options(array_combine($rootCauseOptions, $rootCauseOptions))
                                    ->multiple()
                                    ->placeholder('All root causes'),
                            ]),

                        Select::make('group_dim')
                            ->label('Group sheets by')
                            ->options([
                                'business_category' => 'Business Category (Fraud, Operational, ...)',
                                'root_cause_category' => 'Root Cause Category (Human Error, System Bug, ...)',
                                'responsible_team' => 'Division / Responsible Team (Engineering, Ops, ...)',
                                'pic' => 'PIC (person)',
                                'severity' => 'Severity (P1..P4, X1..X4)',
                                'incident_type' => 'Incident Type (Tech / Non-tech / Company Loss)',
                            ])
                            ->default('business_category')
                            ->visible(fn ($get) => $get('preset') === 'group_by'),

                        Select::make('format')
                            ->label('Format')
                            ->options(['xlsx' => 'XLSX', 'csv' => 'CSV'])
                            ->default('xlsx')
                            ->required()
                            ->visible(fn ($get) => $get('preset') === 'custom'),

                        CheckboxList::make('columns')
                            ->label('Columns to Export')
                            ->options($columnOptions)
                            ->default(array_keys($columnOptions))
                            ->columns(3)
                            ->required()
                            ->visible(fn ($get) => $get('preset') === 'custom'),
                    ];
                })
                ->action(function (array $data) {
                    $query = $this->getFilteredTableQuery()->clone();

                    // Optional export filters (apply on top of the table's current filters)
                    $query->when(! empty($data['f_severity'] ?? []), fn (Builder $q) => $q->whereIn('severity', $data['f_severity']))
                        ->when(! empty($data['f_status'] ?? []), fn (Builder $q) => $q->whereIn('incident_status', $data['f_status']))
                        ->when(! empty($data['f_incident_type'] ?? []), fn (Builder $q) => $q->whereIn('incident_type', $data['f_incident_type']))
                        ->when(! empty($data['f_fund_status'] ?? []), fn (Builder $q) => $q->whereIn('fund_status', $data['f_fund_status']))
                        ->when(! empty($data['f_pic'] ?? []), fn (Builder $q) => $q->whereIn('pic_id', $data['f_pic']))
                        ->when(! empty($data['f_business_category'] ?? []), function (Builder $q) use ($data): void {
                            $q->where(function (Builder $q2) use ($data): void {
                                foreach ($data['f_business_category'] as $cat) {
                                    $q2->orWhereJsonContains('business_category', $cat);
                                }
                            });
                        })
                        ->when(! empty($data['f_root_cause'] ?? []), function (Builder $q) use ($data): void {
                            $q->where(function (Builder $q2) use ($data): void {
                                foreach ($data['f_root_cause'] as $cat) {
                                    $q2->orWhereJsonContains('root_cause_category', $cat);
                                }
                            });
                        });

                    if (($data['preset'] ?? 'executive') === 'executive') {
                        return Excel::download(
                            new \App\Exports\ExecutiveIncidentsExport($query),
                            'executive-report-'.now()->format('Y-m-d').'.xlsx'
                        );
                    }

                    if ($data['preset'] === 'group_by') {
                        return Excel::download(
                            new \App\Exports\GroupedIncidentsExport($query, $data['group_dim'] ?? 'business_category'),
                            'incidents-by-'.($data['group_dim'] ?? 'business_category').'-'.now()->format('Y-m-d').'.xlsx'
                        );
                    }

                    if ($data['preset'] === 'all_tabs') {
                        return Excel::download(
                            new MultiSheetIncidentsExport($query, array_values(self::getColumnOptions()), array_keys(self::getColumnOptions())),
                            'incidents-all-tabs-'.now()->format('Y-m-d').'.xlsx'
                        );
                    }

                    // custom
                    $selectedColumns = $data['columns'];
                    $columnOptions = self::getColumnOptions();
                    $headings = array_values(array_intersect_key($columnOptions, array_flip($selectedColumns)));

                    $format = $data['format'];

                    $query->orderBy('incident_date', 'asc');

                    $totalCases = $query->count();

                    $avgMtbf = app(\App\Filament\Statistics\IncidentStatsFooterData::class)->build($query)['avgMtbf'];

                    $stats = [
                        'totalCases' => $totalCases,
                        'avgMttr' => round($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr') ?? 0, 2),
                        'avgMtbf' => $avgMtbf,
                        'totalPotentialFundLoss' => $query->sum('potential_fund_loss'),
                        'totalFundLoss' => $query->sum('fund_loss'),
                        'totalRecoveredFund' => $query->sum('recovered_fund'),
                    ];

                    $incidents = $query->lazy()->collect();

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

        // Scope to a specific set of incident IDs when the Risk Heat Matrix
        // drills in via ?ids=1,2,3. Only that link sets the param, so this is
        // a no-op for every other entry point (tabs, filters, export, footer).
        return parent::getTableQuery()
            ->when(request()->filled('ids'), function (Builder $query): void {
                $query->whereIn('id', array_filter(explode(',', (string) request()->input('ids'))));
            });
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
