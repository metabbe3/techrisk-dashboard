<?php

namespace App\Filament\Actions;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\User;
use Filament\Forms\Components;
use Illuminate\Database\Eloquent\Builder;

/**
 * Form schema + query filtering for the incident Export action.
 * Extracted from ListIncidents (form ~100 lines + handler ~70 lines).
 */
class ExportActionSchema
{
    /** Column label map for the Custom preset. */
    public static function columnOptions(): array
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

    public static function form(): array
    {
        $severityOptions = collect(Severity::cases())->mapWithKeys(fn ($s) => [$s->value => $s->value])->all();
        $statusOptions = collect(IncidentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->value])->all();
        $typeOptions = collect(IncidentType::cases())->mapWithKeys(fn ($s) => [$s->value => $s->value])->all();
        $fundOptions = collect(FundStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->value])->all();
        $picOptions = User::orderBy('name')->pluck('name', 'id')->all();
        $businessOptions = Incident::distinct()->get('business_category')
            ->pluck('business_category')->filter()->flatMap(fn ($a) => $a)->unique()->sort()->values()->all();
        $rootCauseOptions = Incident::distinct()->get('root_cause_category')
            ->pluck('root_cause_category')->filter()->flatMap(fn ($a) => $a)->unique()->sort()->values()->all();

        return [
            Components\Radio::make('preset')
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

            Components\Section::make('Filter export (optional — applies to every preset)')
                ->description('Leave empty to export the filtered set you see in the table right now.')
                ->collapsed()
                ->schema([
                    Components\Select::make('f_severity')
                        ->label('Severity')
                        ->options($severityOptions)
                        ->multiple()
                        ->placeholder('All severities'),
                    Components\Select::make('f_status')
                        ->label('Incident Status')
                        ->options($statusOptions)
                        ->multiple()
                        ->placeholder('All statuses'),
                    Components\Select::make('f_incident_type')
                        ->label('Incident Type')
                        ->options($typeOptions)
                        ->multiple()
                        ->placeholder('All types'),
                    Components\Select::make('f_fund_status')
                        ->label('Fund Status')
                        ->options($fundOptions)
                        ->multiple()
                        ->placeholder('All fund statuses'),
                    Components\Select::make('f_pic')
                        ->label('PIC')
                        ->options($picOptions)
                        ->multiple()
                        ->searchable()
                        ->placeholder('All PICs'),
                    Components\Select::make('f_business_category')
                        ->label('Business Category')
                        ->options(array_combine($businessOptions, $businessOptions))
                        ->multiple()
                        ->placeholder('All categories'),
                    Components\Select::make('f_root_cause')
                        ->label('Root Cause Category')
                        ->options(array_combine($rootCauseOptions, $rootCauseOptions))
                        ->multiple()
                        ->placeholder('All root causes'),
                ]),

            Components\Select::make('group_dim')
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

            Components\Select::make('format')
                ->label('Format')
                ->options(['xlsx' => 'XLSX', 'csv' => 'CSV'])
                ->default('xlsx')
                ->required()
                ->visible(fn ($get) => $get('preset') === 'custom'),

            Components\CheckboxList::make('columns')
                ->label('Columns to Export')
                ->options(fn () => self::columnOptions())
                ->default(fn () => array_keys(self::columnOptions()))
                ->columns(3)
                ->required()
                ->visible(fn ($get) => $get('preset') === 'custom'),
        ];
    }

    /**
     * Apply the optional export filters on top of the table's current filters.
     */
    public static function applyFilters(Builder $query, array $data): Builder
    {
        return $query
            ->when(! empty($data['f_severity'] ?? []), fn (Builder $q) => $q->whereIn('severity', $data['f_severity']))
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
    }
}
