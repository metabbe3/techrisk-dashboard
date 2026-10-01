<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Exports\IncidentTableExport;
use App\Exports\MultiSheetIncidentsExport;
use App\Filament\Actions\ExportActionSchema;
use App\Filament\Resources\IncidentResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;

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
                ->form(fn () => ExportActionSchema::form())
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
                ->form(fn () => ExportActionSchema::form())
                ->action(function (array $data) {
                    $query = ExportActionSchema::applyFilters($this->getFilteredTableQuery()->clone(), $data);

                    if (($data['preset'] ?? 'executive') === 'executive') {
                        // BUG-013: store → inject chart caches → download, so the
                        // native charts render in Numbers/QuickLook/Sheets too.
                        $export = new \App\Exports\ExecutiveIncidentsExport($query);
                        $fname = 'executive-report-'.now()->format('Y-m-d').'.xlsx';
                        $tmp = storage_path('app/private/temp/'.$fname);
                        @mkdir(dirname($tmp), 0777, true);
                        Excel::store($export, 'temp/'.$fname, 'local');
                        \App\Exports\Concerns\ChartCacheInjector::inject($tmp);

                        return response()->download($tmp, $fname, [
                            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])->deleteFileAfterSend(true);
                    }

                    if ($data['preset'] === 'group_by') {
                        return Excel::download(
                            new \App\Exports\GroupedIncidentsExport($query, $data['group_dim'] ?? 'business_category'),
                            'incidents-by-'.($data['group_dim'] ?? 'business_category').'-'.now()->format('Y-m-d').'.xlsx'
                        );
                    }

                    if ($data['preset'] === 'all_tabs') {
                        return Excel::download(
                            new MultiSheetIncidentsExport($query, array_values(ExportActionSchema::columnOptions()), array_keys(ExportActionSchema::columnOptions())),
                            'incidents-all-tabs-'.now()->format('Y-m-d').'.xlsx'
                        );
                    }

                    // custom
                    $selectedColumns = $data['columns'];
                    $columnOptions = ExportActionSchema::columnOptions();
                    $headings = array_values(array_intersect_key($columnOptions, array_flip($selectedColumns)));

                    $format = $data['format'];

                    $query->orderBy('incident_date', 'asc');

                    $totalCases = $query->count();

                    $avgMtbf = app(\App\Filament\Statistics\IncidentStatsFooterData::class)->build($query)['avgMtbf'];

                    // BUG-021: DECIMAL aggregates are strings on MySQL — cast for
                    // number_format() inside the strict-typed export classes.
                    $stats = [
                        'totalCases' => $totalCases,
                        'avgMttr' => round((float) ($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr') ?? 0), 2),
                        'avgMtbf' => $avgMtbf,
                        'totalPotentialFundLoss' => (float) $query->sum('potential_fund_loss'),
                        'totalFundLoss' => (float) $query->sum('fund_loss'),
                        'totalRecoveredFund' => (float) $query->sum('recovered_fund'),
                    ];

                    $incidents = $query->clone()->with('labels')->lazy()->collect();

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

    public function isTableLoadingDeferred(): bool
    {
        // PERF: the incidents table renders a hover-preview component per row
        // (~45KB HTML/row, 78 Alpine instances at 25 rows). Deferring the load
        // keeps the first paint light and keeps action modals (export) from
        // shipping the whole table with every mountAction response.
        return true;
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
