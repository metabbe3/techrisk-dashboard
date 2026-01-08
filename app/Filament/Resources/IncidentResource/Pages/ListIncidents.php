<?php

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Filament\Resources\IncidentResource;
use App\Exports\IncidentTableExport;
use App\Exports\MultiSheetIncidentsExport;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

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
            Actions\Action::make('export')
                ->label('Export')
                ->icon('heroicon-o-document-arrow-down')
                ->color('primary')
                ->form(function () {
                    $columnOptions = self::getColumnOptions();

                    return [
                        Checkbox::make('export_all_tabs')
                            ->label('Export all tabs as separate sheets (XLSX only)')
                            ->reactive(),
                        Select::make('format')
                            ->label('Format')
                            ->options(['xlsx' => 'XLSX', 'csv' => 'CSV'])
                            ->required()
                            ->visible(fn ($get) => !$get('export_all_tabs')),
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
                            'incidents-all-tabs-' . now()->format('Y-m-d') . '.xlsx'
                        );
                    }

                    $format = $data['format'];
                    
                    $stats = [
                        'totalCases' => $query->count(),
                        'avgMttr' => round($query->avg('mttr'), 2),
                        'avgMtbf' => round($query->avg('mtbf'), 2),
                        'totalPotentialFundLoss' => $query->sum('potential_fund_loss'),
                        'totalFundLoss' => $query->sum('fund_loss'),
                        'totalRecoveredFund' => $query->sum('recovered_fund'),
                    ];
                    
                    $incidents = $query->get();

                    return Excel::download(
                        new IncidentTableExport($incidents, $stats, $headings, $selectedColumns),
                        'incidents-' . now()->format('Y-m-d') . '.' . $format
                    );
                }),
            Actions\CreateAction::make()
                ->visible(fn (): bool => auth()->user()->can('manage incidents')),
        ];
    }

    public function getTabs(): array
    {
        return [
            'All Cases' => Tab::make(),
            'Completed Cases' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('incident_status', 'Completed')),
            'Recovered Cases' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('recovered_fund', '>', 0)),
            'P4 Incidents' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('severity', 'P4')),
            'Non-Tech Incidents' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('incident_type', 'Non-tech')),
            'Fund Loss' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('fund_loss', '>', 0)),
        ];
    }

    public function getTableFooter(): ?View
    {
        // Clone the query to avoid affecting the main table query
        $query = $this->getFilteredTableQuery()->clone();

        $stats = [
            'totalCases' => $query->count(),
            'avgMttr' => round($query->avg('mttr'), 2),
            'avgMtbf' => round($query->avg('mtbf'), 2),
            'totalPotentialFundLoss' => $query->sum('potential_fund_loss'),
            'totalFundLoss' => $query->sum('fund_loss'),
            'totalRecoveredFund' => $query->sum('recovered_fund'),
        ];
        
        return view('livewire.incident-stats-footer', ['stats' => $stats]);
    }
}