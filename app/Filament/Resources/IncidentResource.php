<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Filament\Forms\Components\AiTextarea;
use App\Filament\Resources\IncidentResource\Pages;
use App\Filament\Resources\IncidentResource\RelationManagers;
use App\Models\Category;
use App\Models\Incident;
use App\Models\UserAuditLogSetting;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('view incidents');
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()->can('view incidents');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('manage incidents');
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('manage incidents');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('manage incidents');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::createFormSchema());
    }

    public static function editFormSchema(): array
    {
        return [
            Forms\Components\Tabs::make('EditIncidentTabs')
                ->tabs([
                    Forms\Components\Tabs\Tab::make('General')
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Grid::make(['default' => 1, 'lg' => 3])->schema([
                                Section::make('Core Details')
                                    ->icon('heroicon-o-identification')
                                    ->schema([
                                        TextInput::make('title')->required()->columnSpanFull(),
                                        TextInput::make('no')->label('Incident ID')
                                            ->required()
                                            ->readOnly()
                                            ->unique(ignoreRecord: true),
                                        Select::make('severity')->options(Severity::options())->required(),
                                        Select::make('classification')->options(IncidentClassification::options())->required(),
                                        Select::make('incident_type')->label('Area')->options(IncidentType::options())->required(),
                                        Select::make('incident_type_id')
                                            ->label('Incident Type')
                                            ->relationship('incidentType', 'name')
                                            ->searchable()
                                            ->preload(),
                                        TextInput::make('reported_by')->label('Reported By'),
                                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                                            TextInput::make('mttr')->label('MTTR (minutes)')->readOnly(),
                                            TextInput::make('mtbf')->label('MTBF (days)')->readOnly(),
                                        ]),
                                    ])->columnSpan(['default' => 'full', 'lg' => 2]),

                                Section::make('Status & Assignment')
                                    ->icon('heroicon-o-user-group')
                                    ->schema([
                                        Select::make('incident_status')
                                            ->options(IncidentStatus::options())
                                            ->required()
                                            ->default('Open')
                                            ->live(),
                                        Select::make('incident_source')
                                            ->options(['Internal' => 'Internal', 'External' => 'External'])
                                            ->required(),
                                        Select::make('pic_id')
                                            ->label('Person In Charge')
                                            ->relationship('pic', 'name')
                                            ->searchable()
                                            ->preload(),
                                        TextInput::make('checker'),
                                        TextInput::make('maker'),
                                    ])->columnSpan(['default' => 'full', 'lg' => 1]),
                            ]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Timeline')
                        ->icon('heroicon-o-clock')
                        ->schema([
                            Section::make('Incident Dates')
                                ->icon('heroicon-o-calendar')
                                ->schema([
                                    DateTimePicker::make('incident_date')->label('Occurred Time')->required(),
                                    DateTimePicker::make('discovered_at'),
                                    DateTimePicker::make('stop_bleeding_at'),
                                    DateTimePicker::make('entry_date_tech_risk')->required(),
                                ])->columns(['default' => 1, 'sm' => 2, 'lg' => 4]),

                            Section::make('Categories')
                                ->icon('heroicon-o-tag')
                                ->schema([
                                    Select::make('business_category')
                                        ->label('Business Category')
                                        ->multiple()
                                        ->options(fn () => Category::options(Category::TYPE_BUSINESS_CATEGORY)),
                                    Select::make('root_cause_category')
                                        ->label('Root Cause Category')
                                        ->multiple()
                                        ->options(fn () => Category::options(Category::TYPE_ROOT_CAUSE_CATEGORY)),
                                    Select::make('responsible_team')
                                        ->label('Responsible Team')
                                        ->multiple()
                                        ->options(fn () => Category::options(Category::TYPE_RESPONSIBLE_TEAM)),
                                ])->columns(['default' => 1, 'md' => 3]),

                            Section::make('Financial Impact')
                                ->icon('heroicon-o-currency-dollar')
                                ->schema([
                                    Select::make('fund_status')->options(FundStatus::options()),
                                    TextInput::make('potential_fund_loss')->numeric()->prefix('Rp')->default(0),
                                    TextInput::make('recovered_fund')->numeric()->prefix('Rp')->default(0)->required(),
                                    TextInput::make('fund_loss')->numeric()->prefix('Rp')->default(0)->required(),
                                    TextInput::make('loss_taken_by')->label('Loss Taken By'),
                                ])->columns(['default' => 1, 'sm' => 2, 'xl' => 5]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Content & Analysis')
                        ->icon('heroicon-o-pencil-square')
                        ->extraAttributes(['style' => 'overflow: visible !important;'])
                        ->schema([
                            Section::make('AI Tools')
                                ->icon('heroicon-o-sparkles')
                                ->extraAttributes(['style' => 'overflow: visible !important;'])
                                ->schema([
                                    Forms\Components\View::make('filament.forms.components.ai-root-cause-analysis')->hiddenLabel(),
                                    Forms\Components\View::make('filament.forms.components.ai-similar-incidents')->hiddenLabel(),
                                ])->columns(2),

                            Section::make('Details & Timeline')
                                ->icon('heroicon-o-document-chart-bar')
                                ->schema([
                                    AiTextarea::make('summary')
                                        ->label('Summary')
                                        ->rows(6)
                                        ->helperText('Supports Markdown — **bold**, *italic*, # headings, | tables |, - lists')
                                        ->aiFieldType('summary')
                                        ->columnSpanFull(),
                                    AiTextarea::make('root_cause')
                                        ->label('Root Cause')
                                        ->rows(6)
                                        ->helperText('Supports Markdown — **bold**, *italic*, # headings, | tables |, - lists')
                                        ->aiFieldType('root_cause')
                                        ->columnSpanFull(),
                                    AiTextarea::make('timeline')
                                        ->label('Incident Timeline and Chronology')
                                        ->rows(10)
                                        ->helperText('Supports Markdown — **bold**, *italic*, # headings, | tables |, - lists')
                                        ->aiFieldType('timeline')
                                        ->columnSpanFull(),
                                    AiTextarea::make('remark')
                                        ->label('Remark')
                                        ->rows(4)
                                        ->helperText('Supports Markdown — **bold**, *italic*, # headings, | tables |, - lists')
                                        ->aiFieldType('remark')
                                        ->columnSpanFull(),
                                    Textarea::make('improvements')
                                        ->label('Improvements')
                                        ->rows(4)
                                        ->columnSpanFull()
                                        ->disabled()->hidden(),
                                    TextInput::make('evidence_link')->label('Evidence Link')->url()->columnSpanFull()->disabled()->hidden(),
                                    Textarea::make('evidence')
                                        ->label('Evidence')
                                        ->rows(3)
                                        ->columnSpanFull()
                                        ->disabled()->hidden(),
                                    Select::make('labels')
                                        ->multiple()
                                        ->relationship('labels', 'name')
                                        ->preload()
                                        ->searchable()
                                        ->id('labels-select'),
                                    Forms\Components\View::make('filament.forms.components.smart-labels')->hiddenLabel(),
                                ])->columns(['default' => 1, 'md' => 2]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Admin')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema([
                            Section::make('Upload & Document Status')
                                ->icon('heroicon-o-check-circle')
                                ->schema([
                                    Checkbox::make('goc_upload')->label('GoC Uploaded'),
                                    Checkbox::make('teams_upload')->label('Teams Uploaded'),
                                    Checkbox::make('doc_signed')->label('Doc Signed'),
                                    Checkbox::make('risk_incident_form_cfm')->label('Risk Incident Form CFM'),
                                    Checkbox::make('glitch_flag')->label('Glitch'),
                                    TextInput::make('investigation_pic_status')->label('Investigation PIC Status')->disabled()->hidden(),
                                    TextInput::make('action_improvement_tracking')->label('Action Improvement Tracking')->disabled()->hidden(),
                                    TextInput::make('third_party_client')->label('3rd Party/Client')->disabled()->hidden(),
                                ])->columns(['default' => 1, 'sm' => 2]),
                        ]),
                ])
                ->activeTab(1)
                ->persistTabInQueryString()
                ->columnSpanFull(),
        ];
    }

    public static function createFormSchema(): array
    {
        return [
            Forms\Components\Tabs::make('CreateIncidentTabs')
                ->tabs([
                    Forms\Components\Tabs\Tab::make('General')
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Grid::make(['default' => 1, 'lg' => 3])->schema([
                                Section::make('Core Details')
                                    ->icon('heroicon-o-identification')
                                    ->schema([
                                        TextInput::make('title')->required()->columnSpanFull(),
                                        TextInput::make('no')->label('Incident ID')
                                            ->required()
                                            ->default(fn () => Incident::generateNo('IN'))
                                            ->readOnly()
                                            ->unique(ignoreRecord: true),
                                        Select::make('severity')->options(Severity::options())->required(),
                                        Select::make('classification')->options(IncidentClassification::options())->required(),
                                        Select::make('incident_type')->label('Area')->options(IncidentType::options())->required(),
                                        Select::make('incident_type_id')
                                            ->label('Incident Type')
                                            ->relationship('incidentType', 'name')
                                            ->searchable()
                                            ->preload(),
                                        TextInput::make('reported_by')->label('Reported By'),
                                        TextInput::make('third_party_client')->label('3rd Party/Client')->disabled()->hidden(),
                                    ])->columnSpan(['default' => 'full', 'lg' => 2]),

                                Section::make('Status & Assignment')
                                    ->icon('heroicon-o-user-group')
                                    ->schema([
                                        Select::make('incident_status')
                                            ->options(IncidentStatus::options())
                                            ->required()
                                            ->default('Open')
                                            ->live(),
                                        Select::make('incident_source')
                                            ->options(['Internal' => 'Internal', 'External' => 'External'])
                                            ->required(),
                                        Select::make('pic_id')
                                            ->label('Person In Charge')
                                            ->relationship('pic', 'name')
                                            ->searchable()
                                            ->preload(),
                                        TextInput::make('checker'),
                                        TextInput::make('maker'),
                                    ])->columnSpan(['default' => 'full', 'lg' => 1]),
                            ]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Timeline')
                        ->icon('heroicon-o-clock')
                        ->schema([
                            Section::make('Incident Dates')
                                ->icon('heroicon-o-calendar')
                                ->schema([
                                    DateTimePicker::make('incident_date')->label('Occurred Time')->required(),
                                    DateTimePicker::make('discovered_at'),
                                    DateTimePicker::make('stop_bleeding_at'),
                                    DateTimePicker::make('entry_date_tech_risk')->required(),
                                ])->columns(['default' => 1, 'sm' => 2, 'lg' => 4]),

                            Section::make('Categories')
                                ->icon('heroicon-o-tag')
                                ->schema([
                                    Select::make('business_category')
                                        ->label('Business Category')
                                        ->multiple()
                                        ->options(fn () => Category::options(Category::TYPE_BUSINESS_CATEGORY)),
                                    Select::make('root_cause_category')
                                        ->label('Root Cause Category')
                                        ->multiple()
                                        ->options(fn () => Category::options(Category::TYPE_ROOT_CAUSE_CATEGORY)),
                                    Select::make('responsible_team')
                                        ->label('Responsible Team')
                                        ->multiple()
                                        ->options(fn () => Category::options(Category::TYPE_RESPONSIBLE_TEAM)),
                                ])->columns(['default' => 1, 'md' => 3]),

                            Section::make('Financial Impact')
                                ->icon('heroicon-o-currency-dollar')
                                ->schema([
                                    Select::make('fund_status')->options(FundStatus::options()),
                                    TextInput::make('potential_fund_loss')->numeric()->prefix('Rp')->default(0),
                                    TextInput::make('recovered_fund')->numeric()->prefix('Rp')->default(0)->required(),
                                    TextInput::make('fund_loss')->numeric()->prefix('Rp')->default(0)->required(),
                                    TextInput::make('loss_taken_by')->label('Loss Taken By'),
                                ])->columns(['default' => 1, 'sm' => 2, 'xl' => 5]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Content & Analysis')
                        ->icon('heroicon-o-pencil-square')
                        ->extraAttributes(['style' => 'overflow: visible !important;'])
                        ->schema([
                            Section::make('AI Tools')
                                ->icon('heroicon-o-sparkles')
                                ->extraAttributes(['style' => 'overflow: visible !important;'])
                                ->schema([
                                    Forms\Components\View::make('filament.forms.components.ai-root-cause-analysis')->hiddenLabel(),
                                    Forms\Components\View::make('filament.forms.components.ai-similar-incidents')->hiddenLabel(),
                                ])->columns(2),

                            Section::make('Details & Timeline')
                                ->icon('heroicon-o-document-chart-bar')
                                ->schema([
                                    AiTextarea::make('summary')
                                        ->label('Summary')
                                        ->rows(6)
                                        ->helperText('Supports Markdown — **bold**, *italic*, # headings, | tables |, - lists')
                                        ->aiFieldType('summary')
                                        ->columnSpanFull(),
                                    AiTextarea::make('root_cause')
                                        ->label('Root Cause')
                                        ->rows(6)
                                        ->helperText('Supports Markdown — **bold**, *italic*, # headings, | tables |, - lists')
                                        ->aiFieldType('root_cause')
                                        ->columnSpanFull(),
                                    AiTextarea::make('timeline')
                                        ->label('Incident Timeline and Chronology')
                                        ->rows(10)
                                        ->helperText('Supports Markdown — **bold**, *italic*, # headings, | tables |, - lists')
                                        ->aiFieldType('timeline')
                                        ->columnSpanFull(),
                                    AiTextarea::make('remark')
                                        ->label('Remark')
                                        ->rows(4)
                                        ->helperText('Supports Markdown — **bold**, *italic*, # headings, | tables |, - lists')
                                        ->aiFieldType('remark')
                                        ->columnSpanFull(),
                                    Textarea::make('improvements')
                                        ->label('Improvements')
                                        ->rows(4)
                                        ->columnSpanFull()
                                        ->disabled()->hidden(),
                                    TextInput::make('evidence_link')->label('Evidence Link')->url()->columnSpanFull()->disabled()->hidden(),
                                    Textarea::make('evidence')
                                        ->label('Evidence')
                                        ->rows(3)
                                        ->columnSpanFull()
                                        ->disabled()->hidden(),
                                    Select::make('labels')
                                        ->multiple()
                                        ->relationship('labels', 'name')
                                        ->preload()
                                        ->searchable()
                                        ->id('labels-select'),
                                    Forms\Components\View::make('filament.forms.components.smart-labels')->hiddenLabel(),
                                ])->columns(['default' => 1, 'md' => 2]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Admin')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema([
                            Section::make('Upload & Document Status')
                                ->icon('heroicon-o-check-circle')
                                ->schema([
                                    Checkbox::make('goc_upload')->label('GoC Uploaded'),
                                    Checkbox::make('teams_upload')->label('Teams Uploaded'),
                                    Checkbox::make('doc_signed')->label('Doc Signed'),
                                    Checkbox::make('risk_incident_form_cfm')->label('Risk Incident Form CFM'),
                                    Checkbox::make('glitch_flag')->label('Glitch'),
                                    TextInput::make('investigation_pic_status')->label('Investigation PIC Status')->disabled()->hidden(),
                                    TextInput::make('action_improvement_tracking')->label('Action Improvement Tracking')->disabled()->hidden(),
                                ])->columns(['default' => 1, 'sm' => 2]),
                        ]),
                ])
                ->activeTab(1)
                ->persistTabInQueryString()
                ->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => self::applyAccessControl($query))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['pic', 'incidentType']))
            ->defaultSort('incident_date', 'desc')
            ->columns([
                TextColumn::make('no')->label('ID')->searchable()->sortable()->summarize(Count::make()->label('Total Cases')),
                TextColumn::make('title')
                    ->searchable()
                    ->html()
                    ->formatStateUsing(fn (Incident $record) => view('components.incident-hover-preview', ['incident' => $record])->render()),
                TextColumn::make('mttr_formatted')->label('MTTR')->sortable(query: function (Builder $query, string $direction) {
                    return $query->orderBy('mttr', $direction);
                }),
                TextColumn::make('mtbf_display')
                    ->label('MTBF (days)')
                    ->state(function (Incident $record): int {
                        static $cache = [];
                        $tab = app('activeTab') ?? request()->query('activeTab', 'All Cases');
                        $year = $record->incident_date->year;
                        $key = "{$tab}_{$year}";

                        if (! isset($cache[$key])) {
                            $query = Incident::whereYear('incident_date', $year)
                                ->where('classification', '!=', 'Issue')
                                ->orderBy('incident_date')->orderBy('id');

                            match ($tab) {
                                'On Going' => $query->where('incident_status', '!=', 'Completed'),
                                'Completed Cases' => $query->where('incident_status', 'Completed'),
                                'Recovered Cases' => $query->where('recovered_fund', '>', 0),
                                'P4 Incidents' => $query->where('severity', 'P4'),
                                'Non-Tech Incidents' => $query->where('incident_type', 'Non-tech'),
                                'Fund Loss' => $query->where('fund_status', 'Confirmed loss'),
                                'Potential Recovery' => $query->where('fund_status', 'Potential recovery'),
                                'Fully Recovered' => $query->where('fund_status', 'Fully recovered'),
                                'Non Tech Loss' => $query->where('fund_status', 'Non Tech Loss'),
                                'Non Fund Loss' => $query->where('fund_status', 'Non fundLoss'),
                                'Non Incident' => $query->where('severity', 'Non Incident'),
                                default => null,
                            };

                            $incidents = $query->get(['id', 'incident_date']);
                            $cache[$key] = [];
                            foreach ($incidents as $i => $inc) {
                                $cache[$key][$inc->id] = $i === 0
                                    ? $inc->incident_date->dayOfYear
                                    : (int) $incidents[$i - 1]->incident_date->startOfDay()
                                        ->diffInDays($inc->incident_date->startOfDay());
                            }
                        }

                        return $cache[$key][$record->id] ?? 0;
                    })
                    ->formatStateUsing(fn (int $state): string => number_format($state))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('incident_date', $direction)),
                TextColumn::make('severity')->badge()->color(fn (string $state): string => Severity::tryFrom($state)?->color() ?? 'gray')->sortable(),
                TextColumn::make('incident_status')->badge()->color(fn (string $state): string => IncidentStatus::tryFrom($state)?->color() ?? 'gray')->sortable(),
                TextColumn::make('fund_status')->badge()->color(fn (string $state): string => FundStatus::tryFrom($state)?->color() ?? 'gray')->sortable()->toggleable(),
                TextColumn::make('pic.name')->label('PIC')->sortable()->toggleable(),
                TextColumn::make('incident_date')->dateTime()->sortable(),
                TextColumn::make('potential_fund_loss')->label('Potential Loss')->money('IDR')->sortable()->summarize(Sum::make()->money('IDR')->label('Total Potential')),
                TextColumn::make('recovered_fund')->label('Recovered')->money('IDR')->sortable()->color('success')->summarize(Sum::make()->money('IDR')->label('Total Recovered')),
                TextColumn::make('fund_loss')->label('Actual Loss')->money('IDR')->sortable()->color('danger')->summarize(Sum::make()->money('IDR')->label('Total Loss')),
                TextColumn::make('recovery_rate')->label('Recovery %')->state(function (Incident $record): string {
                    if ($record->potential_fund_loss && $record->potential_fund_loss > 0) {
                        $rate = ($record->recovered_fund / $record->potential_fund_loss) * 100;

                        return number_format($rate, 1).'%';
                    }

                    return '0%';
                })->color(fn (string $state): string => (floatval($state) >= 100) ? 'success' : ((floatval($state) > 0) ? 'warning' : 'gray')),

                TextColumn::make('classification')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('incident_type')->label('Area')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('business_category')->label('Business Category')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('root_cause_category')->label('Root Cause Category')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('responsible_team')->label('Responsible Team')->badge()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('goc_upload')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                'incident_status',
                'severity',
                Group::make('incident_date')
                    ->label('Incident Month')
                    ->getTitleFromRecordUsing(fn (Incident $record): ?string => $record->incident_date?->format('F Y'))
                    ->collapsible(),
            ])
            ->filters([
                SelectFilter::make('multi_column_sort')
                    ->label('Sort By')
                    ->options([
                        'date_desc' => 'Date (Newest First)',
                        'date_asc' => 'Date (Oldest First)',
                        'date_status' => 'Date → Status (Open First)',
                        'status_date' => 'Status → Date (Open First)',
                        'status_date_asc' => 'Status → Date (Oldest First)',
                        'status_severity' => 'Status → Severity (P1 First)',
                        'severity_date' => 'Severity → Date (P1 First)',
                        'pic_date' => 'PIC → Date',
                        'mttr_desc' => 'MTTR (Highest First)',
                        'loss_desc' => 'Fund Loss (Highest First)',
                    ])
                    ->indicateUsing(function (array $data): ?string {
                        $value = $data['value'] ?? null;
                        if ($value === null) {
                            return null;
                        }

                        $labels = [
                            'date_desc' => 'Sort: Date (Newest)',
                            'date_asc' => 'Sort: Date (Oldest)',
                            'date_status' => 'Sort: Date → Status',
                            'status_date' => 'Sort: Status → Date (Newest)',
                            'status_date_asc' => 'Sort: Status → Date (Oldest)',
                            'status_severity' => 'Sort: Status → Severity',
                            'severity_date' => 'Sort: Severity → Date',
                            'pic_date' => 'Sort: PIC → Date',
                            'mttr_desc' => 'Sort: MTTR',
                            'loss_desc' => 'Sort: Fund Loss',
                        ];

                        return $labels[$value] ?? "Sort: {$value}";
                    })
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;

                        if ($value === null) {
                            return $query;
                        }

                        $statusOrder = IncidentStatus::fieldOrderExpression();
                        $severityOrder = Severity::fieldOrderExpression();

                        return match ($value) {
                            'date_desc' => $query->orderBy('incident_date', 'desc'),
                            'date_asc' => $query->orderBy('incident_date', 'asc'),
                            'date_status' => $query->orderBy('incident_date', 'desc')
                                ->orderByRaw($statusOrder),
                            'status_date' => $query->orderByRaw($statusOrder)
                                ->orderBy('incident_date', 'desc'),
                            'status_date_asc' => $query->orderByRaw($statusOrder)
                                ->orderBy('incident_date', 'asc'),
                            'status_severity' => $query->orderByRaw($statusOrder)
                                ->orderByRaw($severityOrder),
                            'severity_date' => $query->orderByRaw($severityOrder)
                                ->orderBy('incident_date', 'desc'),
                            'pic_date' => $query->orderBy('pic_id')->orderBy('incident_date', 'desc'),
                            'mttr_desc' => $query->orderBy('mttr', 'desc')->orderBy('incident_date', 'desc'),
                            'loss_desc' => $query->orderBy('fund_loss', 'desc')->orderBy('incident_date', 'desc'),
                            default => $query,
                        };
                    }),

                SelectFilter::make('severity')
                    ->label('Severity')
                    ->options(Severity::options())
                    ->multiple(),
                SelectFilter::make('incident_status')
                    ->label('Status')
                    ->options(IncidentStatus::options())
                    ->multiple(),
                SelectFilter::make('incident_type')
                    ->label('Type')
                    ->options(IncidentType::options())
                    ->multiple(),
                SelectFilter::make('classification')
                    ->label('Classification')
                    ->options(IncidentClassification::options())
                    ->multiple(),

                \App\Filament\Filters\QuickPeriodFilter::make(),
                \App\Filament\Filters\QuickPeriodFilter::dateRange(),

                SelectFilter::make('fund_status')
                    ->label('Fund Status')
                    ->options(FundStatus::filterOptions()),

                SelectFilter::make('labels')
                    ->label('Labels')
                    ->multiple()
                    ->options(fn () => \App\Models\Label::orderBy('name')->pluck('name', 'name')->toArray())
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['values'])) {
                            return $query;
                        }

                        return $query->whereHas('labels', fn (Builder $q) => $q->whereIn('name', $data['values']));
                    }),

                SelectFilter::make('pic_id')
                    ->label('PIC')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => \App\Models\User::orderBy('name')->pluck('name', 'id')->toArray()),

                SelectFilter::make('incident_source')
                    ->label('Source')
                    ->multiple()
                    ->options(['Internal' => 'Internal', 'External' => 'External']),

                SelectFilter::make('glitch_flag')
                    ->label('Glitch')
                    ->options([1 => 'Yes', 0 => 'No']),

                SelectFilter::make('business_category')
                    ->label('Business Category')
                    ->multiple()
                    ->options(fn () => \App\Models\Category::options(\App\Models\Category::TYPE_BUSINESS_CATEGORY))
                    ->query(function (Builder $query, array $data) {
                        foreach ($data['values'] ?? [] as $value) {
                            $query->whereJsonContains('business_category', $value);
                        }

                        return $query;
                    }),

                SelectFilter::make('root_cause_category')
                    ->label('Root Cause Category')
                    ->multiple()
                    ->options(fn () => \App\Models\Category::options(\App\Models\Category::TYPE_ROOT_CAUSE_CATEGORY))
                    ->query(function (Builder $query, array $data) {
                        foreach ($data['values'] ?? [] as $value) {
                            $query->whereJsonContains('root_cause_category', $value);
                        }

                        return $query;
                    }),

                SelectFilter::make('responsible_team')
                    ->label('Responsible Team')
                    ->multiple()
                    ->options(fn () => \App\Models\Category::options(\App\Models\Category::TYPE_RESPONSIBLE_TEAM))
                    ->query(function (Builder $query, array $data) {
                        foreach ($data['values'] ?? [] as $value) {
                            $query->whereJsonContains('responsible_team', $value);
                        }

                        return $query;
                    }),

                Tables\Filters\Filter::make('fund_loss_range')
                    ->label('Fund Loss Range')
                    ->form([
                        Forms\Components\TextInput::make('min')->label('Min Loss')->numeric(),
                        Forms\Components\TextInput::make('max')->label('Max Loss')->numeric(),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['min'] ?? null, fn ($q, $min) => $q->where('fund_loss', '>=', $min))
                            ->when($data['max'] ?? null, fn ($q, $max) => $q->where('fund_loss', '<=', $max));
                    }),

                Tables\Filters\Filter::make('missing_root_cause')
                    ->label('Missing Root Cause')
                    ->form([
                        Forms\Components\Toggle::make('enabled')->label('No root cause analysis'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (! ($data['enabled'] ?? false)) {
                            return $query;
                        }

                        return $query->where(fn (Builder $q) => $q->whereNull('root_cause')->orWhere('root_cause', '')->orWhere('root_cause', 'N/A'));
                    }),

                \App\Filament\Filters\ContentSearchFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->iconButton(),
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->databaseTransaction()
                    ->visible(fn (): bool => auth()->user()->can('manage incidents')),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('update_status')
                        ->label('Update Status')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->form([
                            Forms\Components\Select::make('incident_status')
                                ->label('Status')
                                ->options(IncidentStatus::options())
                                ->required()
                                ->default(fn (Incident $record) => $record->incident_status),
                            Forms\Components\DateTimePicker::make('update_date')
                                ->label('Update Date')
                                ->seconds(false)
                                ->default(fn (Incident $record) => $record->incident_date)
                                ->required(),
                            Forms\Components\Textarea::make('remark')
                                ->label('Notes')
                                ->required()
                                ->rows(3)
                                ->default(fn (Incident $record) => $record->remark),
                        ])
                        ->action(function (Incident $record, array $data) {
                            $record->update([
                                'incident_status' => $data['incident_status'],
                                'incident_date' => $data['update_date'],
                                'remark' => $data['remark'],
                            ]);
                        })
                        ->visible(fn (): bool => auth()->user()->can('manage incidents')),
                    Tables\Actions\Action::make('downgrade_to_issue')
                        ->label('Downgrade to Issue')
                        ->icon('heroicon-o-arrow-down-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Downgrade to Issue')
                        ->modalDescription('This will reclassify this incident as an issue and assign a new Issue ID.')
                        ->action(fn (Incident $record) => $record->changeClassification('Issue'))
                        ->visible(fn (): bool => auth()->user()->can('manage incidents')),
                    Tables\Actions\DeleteAction::make()
                        ->databaseTransaction()
                        ->visible(fn (): bool => auth()->user()->can('manage incidents')),
                ])
                    ->label('')
                    ->icon('heroicon-o-ellipsis-horizontal')
                    ->button(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulk_downgrade')
                        ->label('Downgrade to Issue')
                        ->icon('heroicon-o-arrow-down-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Downgrade selected incidents to issues')
                        ->modalDescription('All selected incidents will be reclassified as issues with new IDs.')
                        ->action(fn (\Illuminate\Support\Collection $records) => $records->each->changeClassification('Issue'))
                        ->visible(fn (): bool => auth()->user()->can('manage incidents'))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->databaseTransaction()
                        ->visible(fn (): bool => auth()->user()->can('manage incidents')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\StatusUpdatesRelationManager::class,
            RelationManagers\InvestigationDocumentsRelationManager::class,
            RelationManagers\AuditsRelationManager::class,
            RelationManagers\ActionImprovementsRelationManager::class,
            RelationManagers\WarRoomSessionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncidents::route('/'),
            'create' => Pages\CreateIncident::route('/create'),
            'edit' => Pages\EditIncident::route('/{record}/edit'),
            'view' => Pages\ViewIncident::route('/{record}'),
        ];
    }

    protected static function applyAccessControl(Builder $query): Builder
    {
        $query = $query->where('classification', '!=', 'Issue');

        $user = auth()->user();
        if (! $user) {
            return $query;
        }

        if ($user->hasRole('admin')) {
            return $query;
        }

        $settings = UserAuditLogSetting::forUser($user);

        if (! $settings->can_view_all_logs && ! empty($settings->allowed_years)) {
            $query->whereYear('incident_date', $settings->allowed_years);
        }

        return $query;
    }
}
