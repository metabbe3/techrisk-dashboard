<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\DetectedVia;
use App\Enums\FailedControl;
use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Filament\Actions\FinancialImpactFormSchema;
use App\Filament\Actions\SubIncidentLinkAction;
use App\Filament\Forms\Components\AiTextarea;
use App\Filament\Resources\IncidentResource\Pages;
use App\Filament\Resources\IncidentResource\RelationManagers;
use App\Models\Category;
use App\Models\Incident;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Facades\Cache;

class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * Global search (Spotlight palette record search + Filament global
     * search) — the incident number and title are the fields users search by.
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['no', 'title'];
    }

    /**
     * ONE-LOGIC: global search must run through the SAME access control as
     * the table (non-Issue classification + user year access). The default
     * (parent) returns getEloquentQuery(), which IncidentResource does NOT
     * override — access control lives on the table via modifyQueryUsing — so
     * without this override the palette would search EVERY incident regardless
     * of the user's year access.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::applyAccessControl(parent::getGlobalSearchEloquentQuery());
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->no.' — '.$record->title;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Status' => $record->incident_status?->value ?? '—',
            'Incident date' => $record->incident_date?->format('d M Y') ?? '—',
        ];
    }

    // Null guard: the Spotlight palette evaluates canViewAny/canView/canCreate
    // for every resource on guest pages too (login) — a missing user must
    // mean "no", never a 500.
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('view incidents');
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();

        if (! $user?->can('view incidents')) {
            return false;
        }

        // Record gate — the ONE scope for EVERYONE (not just team-viewers):
        // a deep link can never show more than the list did. Admins pass
        // unscoped, team-viewers need a slice match, year-restricted users
        // get the allowed-years filter.
        return (bool) static::applyAccessControl(
            Incident::query()->whereKey($record->getKey())
        )->exists();
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('manage incidents');
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
        return $form->schema(static::getFormSchema('create'));
    }

    public static function getFormSchema(string $context = 'create'): array
    {
        $isEdit = $context === 'edit';

        $noField = TextInput::make('no')
            ->label('Incident ID')
            ->required()
            ->readOnly()
            ->unique(ignoreRecord: true);

        if (! $isEdit) {
            $noField->default(fn () => Incident::generateNo('IN'));
        }

        $coreDetails = [
            // PROJ-022: create-as-sub entry — CreateIncident?parent=<id> fills
            // this from the URL; a normal create leaves it null.
            // Review I4: the URL is craftable — validate the candidate (exists,
            // a super-grade Incident, not itself linked) or a crafted
            // ?parent=<sub|issue|garbage> chains depth-2 hierarchies, hangs a
            // family off an Issue, or 500s on the FK.
            Forms\Components\Hidden::make('parent_incident_id')
                ->dehydrated()
                // Hidden fields are EXCLUDED from validation unless this flag
                // is set (HasState defaults isDehydratedWhenHidden false) —
                // without it the rules below never ran.
                ->dehydratedWhenHidden()
                ->rules([
                    fn (?Incident $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record): void {
                        // Owner 2026-11-17: on the EDIT page the hidden field
                        // carries the record's own link — the super would
                        // fail the "sits at the top" check below (it has
                        // subs by definition). Only an untouched link is
                        // exempt; any other value is still validated.
                        if ($record !== null && (int) $value === (int) $record->parent_incident_id) {
                            return;
                        }

                        if (blank($value)) {
                            return;
                        }

                        $parent = Incident::find($value);

                        if ($parent === null
                            || $parent->classification !== IncidentClassification::Incident
                            || filled($parent->parent_incident_id)
                            || $parent->subIncidents()->exists()) {
                            $fail('The selected parent is not a valid super-incident: it must exist, be an Incident (not an Issue), and sit at the top of its hierarchy.');
                        }
                    },
                ])
                ->default(fn () => request()->filled('parent') ? (int) request()->query('parent') : null),
            Forms\Components\Placeholder::make('sub_incident_hint')
                ->content(fn ($get) => filled($get('parent_incident_id'))
                    ? 'Recording as a sub-incident of '.Incident::find($get('parent_incident_id'))?->no
                    : null)
                ->visible(fn ($get) => filled($get('parent_incident_id')))
                ->columnSpanFull(),
            TextInput::make('title')->required()->columnSpanFull(),
            $noField,
            Select::make('severity')->options(Severity::options())->required(),
            Select::make('classification')->options(IncidentClassification::options())->required()
                // PROJ-022: a record linked in a hierarchy cannot change
                // classification until unlinked — the guard behind every
                // metric-scope rule in this feature.
                // Owner 2026-11-17: the fill state holds the cast ENUM
                // (EditIncident fills from the record's cast attributes) and
                // an untouched create submit can hold it too — normalize to
                // the raw value first, or the strict !== reads enum !==
                // 'Incident' as changed and EVERY edit of a linked record
                // fails this guard without touching the field.
                ->rules([
                    fn (?Incident $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record): void {
                        $submitted = $value instanceof \BackedEnum ? $value->value : $value;

                        if ($record !== null
                            && $record->hasIncidentLinks()
                            && $submitted !== $record->classification?->value) {
                            $fail('Unlink this incident from its super/sub-incidents before changing classification.');
                        }
                    },
                ]),
            Select::make('incident_type')->label('Area')->options(IncidentType::options())->required(),
            Select::make('incident_type_id')
                ->label('Incident Type')
                ->relationship('incidentType', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('reported_by')->label('Reported By'),
        ];

        if ($isEdit) {
            $coreDetails[] = Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('mttr')->label('MTTR (minutes)')->readOnly(),
                TextInput::make('mtbf')->label('MTBF (days)')->readOnly(),
            ]);
        } else {
            $coreDetails[] = TextInput::make('third_party_client')->label('3rd Party/Client')->disabled()->hidden();
        }

        $adminFields = [
            Checkbox::make('goc_upload')->label('GoC Uploaded'),
            Checkbox::make('teams_upload')->label('Teams Uploaded'),
            Checkbox::make('doc_signed')->label('Doc Signed'),
            Checkbox::make('risk_incident_form_cfm')->label('Risk Incident Form CFM'),
            Checkbox::make('glitch_flag')->label('Glitch'),
            TextInput::make('investigation_pic_status')->label('Investigation PIC Status')->disabled()->hidden(),
            TextInput::make('action_improvement_tracking')->label('Action Improvement Tracking')->disabled()->hidden(),
        ];

        if ($isEdit) {
            $adminFields[] = TextInput::make('third_party_client')->label('3rd Party/Client')->disabled()->hidden();
        }

        return [
            Forms\Components\Tabs::make($isEdit ? 'EditIncidentTabs' : 'CreateIncidentTabs')
                ->tabs([
                    Forms\Components\Tabs\Tab::make('General')
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Grid::make(['default' => 1, 'lg' => 3])->schema([
                                Section::make('Core Details')
                                    ->icon('heroicon-o-identification')
                                    ->schema($coreDetails)
                                    ->columnSpan(['default' => 'full', 'lg' => 2]),

                                Section::make('Status & Assignment')
                                    ->icon('heroicon-o-user-group')
                                    ->schema([
                                        Select::make('incident_status')
                                            ->options(IncidentStatus::options())
                                            ->required()
                                            ->default('Open')
                                            ->live()
                                            // BUG-035: mirror the observer gate as a field rule so the
                                            // error renders here instead of a silent rollback.
                                            ->rule(fn (Get $get, ?Incident $record) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                                                $status = $value instanceof IncidentStatus ? $value : IncidentStatus::tryFrom((string) $value);
                                                if ($status !== IncidentStatus::Completed || $record?->incident_status === IncidentStatus::Completed) {
                                                    return;
                                                }
                                                $error = Incident::closureGateError($get('root_cause'), $get('root_cause_category'), $get('stop_bleeding_at'));
                                                if ($error !== null) {
                                                    $fail($error.' (Timeline / Root Cause tabs)');
                                                }
                                            })
                                            ->helperText('Completing requires a root cause and a stop bleeding time (closure gate, RD-2).'),
                                        Select::make('incident_source')
                                            ->options(['Internal' => 'Internal', 'External' => 'External'])
                                            ->required(),
                                        Select::make('pics')
                                            ->label('PIC Tech Risk')
                                            ->relationship('pics', 'name')
                                            ->multiple()
                                            ->searchable(),
                                        Select::make('picIncident')
                                            ->label('PIC Incident')
                                            ->relationship('picIncident', 'name')
                                            ->multiple()
                                            ->searchable(),
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
                                    DateTimePicker::make('incident_date')->label('Occurred Time')->required()->maxDate(now())->native(false)->seconds(false),
                                    DateTimePicker::make('discovered_at')->afterOrEqual('incident_date')->native(false)->seconds(false),
                                    DateTimePicker::make('stop_bleeding_at')->afterOrEqual('incident_date')->native(false)->seconds(false),
                                    DateTimePicker::make('entry_date_tech_risk')->required()->maxDate(now())->native(false)->seconds(false),
                                    Select::make('detected_via')
                                        ->label('Detected Via')
                                        ->options(DetectedVia::options())
                                        ->helperText('Who caught it first — feeds the channel-mix KPI'),
                                    Select::make('failed_control')
                                        ->label('Failed Control')
                                        ->options(FailedControl::options())
                                        ->helperText('Prevention / Detection / Response — set at closure'),
                                    Toggle::make('start_time_known')
                                        ->label('Occurrence time is exact')
                                        ->helperText('Leave off when only the date is known — 00:00/12:00 stays a placeholder instead of fake precision.'),
                                    TextInput::make('detection_delay_days')
                                        ->label('Started days before discovery')
                                        ->numeric()->minValue(0)->maxValue(3650)
                                        ->helperText('Estimated gap when the true start time is unknown (RD-4).'),
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
                                    // Money fields (format/parse = id-ID, see
                                    // FinancialImpactFormSchema) live in the
                                    // shared schema class.
                                    ...FinancialImpactFormSchema::fields(),
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
                                ->schema($adminFields)
                                ->columns(['default' => 1, 'sm' => 2]),
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['pics', 'incidentType', 'labels', 'superIncident:id,no,title', 'statusUpdates' => fn ($u) => $u->orderByDesc('update_date')->orderByDesc('id')]))
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('subIncidents')) // PROJ-022 chevron/badge visibility
            // PROJ-022 (owner 2026-11-16): the super's money display is the
            // family total — carry the sub sums in one query so the money
            // columns never per-row query (familyMoney() reads them).
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withSum('subIncidents', 'potential_fund_loss')
                ->withSum('subIncidents', 'recovered_fund')
                ->withSum('subIncidents', 'fund_loss'))
            ->defaultSort('incident_date', 'desc')
            ->columns([
                // PROJ-022: expand column ikut sticky kiri — geometry kolom
                // (ID left = lebar expand) diukur runtime oleh app.js
                // syncStickyIdWidths (var --sticky-expand-w), rules CSS di
                // app.css. Row-conditional ->hidden() is not available on
                // columns (it is evaluated once per column set, with no
                // record) — a null icon / null badge state renders the cell
                // empty on sub-less rows instead.
                IconColumn::make('expand_sub_incidents')
                    ->label('')
                    ->width('20px')
                    ->extraHeaderAttributes(['class' => 'sticky-col-expand'])
                    ->extraCellAttributes(['class' => 'sticky-col-expand'])
                    // Review C1: the column needs a non-null state or the icon
                    // blade never evaluates the icon closure (empty invisible
                    // button); closures in this static table() can NOT capture
                    // $this — the component arrives by injection as $livewire.
                    ->state(fn (Incident $record) => $record->sub_incidents_count)
                    ->icon(fn (Incident $record, $livewire) => $record->sub_incidents_count === 0
                        ? null
                        : (in_array($record->getKey(), $livewire->expandedSubIncidents) ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'))
                    ->action(fn (Incident $record, $livewire) => $record->sub_incidents_count > 0
                        ? $livewire->toggleExpandedSubIncidents((int) $record->getKey())
                        : null),
                TextColumn::make('no')->label('ID')->searchable()->sortable()->width('80px')->summarize(Count::make()->label('Total Cases'))
                    ->extraHeaderAttributes(['class' => 'sticky-col-id'])
                    ->extraCellAttributes(['class' => 'sticky-col-id']),
                TextColumn::make('title')
                    ->searchable()
                    ->html()
                    ->width('200px')
                    ->extraHeaderAttributes(['class' => 'sticky-col-title'])
                    ->extraCellAttributes(['class' => 'sticky-col-title'])
                    ->formatStateUsing(fn (Incident $record) => view('components.incident-hover-preview', ['incident' => $record])->render())
                    ->description(fn (Incident $record) => $record->parent_incident_id ? "Sub of: {$record->superIncident?->no}" : null),
                TextColumn::make('sub_incidents_count')
                    ->label('Subs')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state : null),
                TextColumn::make('mttr_formatted')->label('MTTR')->width('70px')->sortable(query: function (Builder $query, string $direction) {
                    return $query->orderBy('mttr', $direction);
                }),
                TextColumn::make('mtbf_display')
                    ->label('MTBF (days)')
                    ->width('80px')
                    ->state(function (Incident $record): int|string {
                        // Outlier rows show the literal, never a gap value
                        // (owner rule 2026-10-01).
                        if ($record->isOutlier()) {
                            return 'Outlier';
                        }

                        $tab = app('activeTab') ?? request()->query('activeTab', 'All Cases');
                        $year = $record->incident_date->year;
                        // v2 in the key: the chain now drops non-metric rows
                        // (METRIC_ELIGIBLE) and renders '-' on the year's
                        // first row — old cached maps served dayOfYear.
                        // A dashboard_cache_version bump invalidates for free.
                        $cacheKey = "mtbf_{$tab}_{$year}_v2_".Cache::get('dashboard_cache_version', 0);

                        $mtbf = Cache::remember($cacheKey, now()->addHour(), function () use ($tab, $year) {
                            $query = Incident::whereYear('incident_date', $year)
                                ->where('classification', '!=', IncidentClassification::Issue->value)
                                ->whereIn('severity', Severity::METRIC_ELIGIBLE)
                                ->withoutOutliers()
                                ->orderBy('incident_date')->orderBy('id');

                            match ($tab) {
                                'On Going' => $query->where('incident_status', '!=', IncidentStatus::Completed->value),
                                'Completed Cases' => $query->where('incident_status', IncidentStatus::Completed->value),
                                'Recovered Cases' => $query->where('recovered_fund', '>', 0),
                                'P4 Incidents' => $query->where('severity', Severity::P4->value),
                                'Non-Tech Incidents' => $query->where('incident_type', IncidentType::NonTech->value),
                                'Fund Loss' => $query->where('fund_status', FundStatus::ConfirmedLoss->value),
                                'Potential Recovery' => $query->where('fund_status', FundStatus::PotentialRecovery->value),
                                'Fully Recovered' => $query->where('fund_status', FundStatus::FullyRecovered->value),
                                'Non Tech Loss' => $query->where('fund_status', FundStatus::NonTechLoss->value),
                                'Non Fund Loss' => $query->where('fund_status', FundStatus::NonFundLoss->value),
                                'Non Incident' => $query->where('severity', Severity::NonIncident->value),
                                default => null,
                            };

                            $incidents = $query->get(['id', 'incident_date']);
                            // Export chain semantics (one logic, owner
                            // 2026-11-16): first of the year renders '-', so
                            // the column's mean = span/(n-1) like every
                            // footer. G/Non Incident rows are not in the
                            // chain (METRIC_ELIGIBLE) and render '-' too.
                            $result = [];
                            foreach ($incidents as $i => $inc) {
                                $result[$inc->id] = $i === 0
                                    ? '-'
                                    : (int) $incidents[$i - 1]->incident_date->startOfDay()
                                        ->diffInDays($inc->incident_date->startOfDay());
                            }

                            return $result;
                        });

                        return $mtbf[$record->id] ?? '-';
                    })
                    ->formatStateUsing(fn (int|string $state): string => is_int($state) ? number_format($state) : $state)
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('incident_date', $direction)),
                TextColumn::make('severity')->badge()->color(fn (?Severity $state): string => $state?->color() ?? 'gray')->sortable()->width('80px'),
                TextColumn::make('incident_status')->badge()->color(fn (?IncidentStatus $state): string => $state?->color() ?? 'gray')->sortable()->width('100px'),
                // Owner rule (2026-11-09/10): status-update freshness flag —
                // rule lives on Incident::statusUpdateFlag() (shared with
                // the reminder email lane so the two cannot drift).
                TextColumn::make('follow_up')
                    ->label('Status update')
                    ->badge()
                    ->color(fn (?string $state): string => $state === Incident::FLAG_NEED_UPDATE ? 'danger' : 'warning')
                    ->state(fn (Incident $record): ?string => $record->statusUpdateFlag()),
                TextColumn::make('fund_status')->badge()->color(fn (?FundStatus $state): string => $state?->color() ?? 'gray')->sortable()->toggleable()->width('120px'),
                TextColumn::make('pics.name')->label('PIC')->badge()->toggleable()->width('100px'),
                TextColumn::make('incident_date')->dateTime()->sortable()->width('100px'),
                // PROJ-022 (owner 2026-11-16): a super's money columns show
                // the FAMILY total (itself + subs); subs and standalones keep
                // their own values. Display-only — the sort/summary bases
                // stay on the stored columns.
                TextColumn::make('potential_fund_loss')->label('Potential Loss')->money('IDR')->sortable()->width('110px')->state(fn (Incident $record): float => $record->familyMoney()['potential'])->summarize(Sum::make()->money('IDR')->label('Total Potential')),
                TextColumn::make('recovered_fund')->label('Recovered')->money('IDR')->sortable()->color('success')->width('100px')->state(fn (Incident $record): float => $record->familyMoney()['recovered'])->summarize(Sum::make()->money('IDR')->label('Total Recovered')),
                TextColumn::make('fund_loss')->label('Actual Loss')->money('IDR')->sortable()->color('danger')->width('100px')->state(fn (Incident $record): float => $record->familyMoney()['actual'])->summarize(Sum::make()->money('IDR')->label('Total Loss')),
                TextColumn::make('recovery_rate')->label('Recovery %')->state(function (Incident $record): string {
                    // Family base too — same roll-up as the money columns.
                    $rate = $record->familyRecoveryRate();

                    return $rate > 0 ? number_format($rate, 1).'%' : '0%';
                })->color(fn (string $state): string => (floatval($state) >= 100) ? 'success' : ((floatval($state) > 0) ? 'warning' : 'gray'))->width('80px'),

                TextColumn::make('classification')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('detected_via')->label('Detected Via')->badge()->color(fn (?DetectedVia $state): string => $state?->color() ?? 'gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failed_control')->label('Failed Control')->badge()->color(fn (?FailedControl $state): string => $state?->color() ?? 'gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('incident_type')->label('Area')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('business_category')->label('Business Category')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('root_cause_category')->label('Root Cause Category')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('responsible_team')->label('Responsible Team')->badge()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('goc_upload')->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('notion_investigation_link')
                    ->label('Notion')
                    ->state(fn (Incident $record): bool => filled($record->notion_investigation_page_id))
                    ->trueIcon('heroicon-o-arrow-top-right-on-square')
                    ->trueColor('info')
                    ->tooltip(fn (Incident $record): ?string => filled($record->notion_investigation_page_id)
                        ? 'Open the Notion investigation page'
                        : null)
                    ->url(fn (Incident $record): ?string => filled($record->notion_investigation_page_id)
                        ? app(\App\Services\Notion\NotionInvestigationService::class)->pageUrl((string) $record->notion_investigation_page_id)
                        : null)
                    // Owner 2026-11-18: without a page the cell must navigate
                    // NOWHERE — Filament's ListRecords wraps every cell in an
                    // anchor to the detail page (the table carries a
                    // ViewAction, so the row's default recordUrl exists), and
                    // a null column URL falls back to it. Disable the click
                    // per record so the cell renders a plain div when no page
                    // is linked; the Notion anchor itself stays.
                    ->disableClick(fn (Incident $record): bool => blank($record->notion_investigation_page_id))
                    ->openUrlInNewTab(),
            ])
            ->groups([
                Group::make('incident_status')
                    ->getTitleFromRecordUsing(fn (Incident $record): ?string => $record->incident_status?->value),
                Group::make('severity')
                    ->getTitleFromRecordUsing(fn (Incident $record): ?string => $record->severity?->value),
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
                SelectFilter::make('pic')
                    ->label('PIC')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => \App\Models\User::orderBy('name')->pluck('name', 'id')->toArray())
                    ->query(fn (Builder $query, array $data) => filled($data['values'] ?? null)
                        ? $query->whereHas('pics', fn (Builder $q) => $q->whereIn('users.id', $data['values']))
                        : $query),
                SelectFilter::make('fund_status')
                    ->label('Fund Status')
                    ->options(FundStatus::filterOptions()),
                SelectFilter::make('classification')
                    ->label('Classification')
                    ->options(IncidentClassification::options())
                    ->multiple(),
                SelectFilter::make('detected_via')
                    ->label('Detected Via')
                    ->options(DetectedVia::options())
                    ->multiple(),
                SelectFilter::make('failed_control')
                    ->label('Failed Control')
                    ->options(FailedControl::options())
                    ->multiple(),

                \App\Filament\Filters\QuickPeriodFilter::make(),
                \App\Filament\Filters\QuickPeriodFilter::dateRange(),

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

                Tables\Filters\SelectFilter::make('investigation_form_status')
                    ->label('Investigation Form')
                    ->options([
                        'pending' => 'Pending (open)',
                        'expired' => 'Expired',
                        'submitted' => 'Submitted',
                        'none' => 'Not Requested',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'pending' => $query->whereHas('investigationFormRequests', fn (Builder $q) => $q->open()),
                            'expired' => $query->whereHas('investigationFormRequests', fn (Builder $q) => $q->expired()),
                            'submitted' => $query->whereHas('investigationFormRequests', fn (Builder $q) => $q->where('status', \App\Models\InvestigationFormRequest::STATUS_SUBMITTED)),
                            'none' => $query->whereDoesntHave('investigationFormRequests'),
                            default => $query,
                        };
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
                    Tables\Actions\Action::make('start_investigation')
                        ->label('Start Investigation')
                        ->icon('heroicon-o-magnifying-glass')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Start Investigation')
                        ->modalDescription('Creates a Notion investigation page, links it to this record, and sets the status to In progress. On a soft-unlinked record it continues the existing page instead.')
                        // Round 10: a soft-unlinked record keeps its page id —
                        // Start Investigation shows again and CONTINUES it.
                        ->action(fn (Incident $record) => \App\Filament\Actions\NotionInvestigationAction::start($record))
                        ->visible(fn (Incident $record): bool => auth()->user()->can('manage incidents')
                            && (blank($record->notion_investigation_page_id) || filled($record->notion_investigation_unlinked_at))),
                    // ONE definition shared with the view page's kebab
                    // (owner 2026-11-21): form + BUG-035 gate + write live in
                    // App\Filament\Actions\UpdateStatusAction.
                    \App\Filament\Actions\UpdateStatusAction::table(),
                    // PROJ-022: sub-incident hierarchy actions. A super's
                    // family grows (multiple subs); subs themselves cannot
                    // supers (one level deep), so link/create hide on them.
                    Tables\Actions\Action::make('create_sub_incident')
                        ->label('Create sub-incident')
                        ->icon('heroicon-o-plus-circle')
                        ->color('success')
                        ->url(fn (Incident $record): string => IncidentResource::getUrl('create', ['parent' => $record->getKey()]))
                        ->visible(fn (Incident $record): bool => auth()->user()->can('manage incidents')
                            && $record->classification === IncidentClassification::Incident
                            && blank($record->parent_incident_id)),
                    Tables\Actions\Action::make('link_sub_incident')
                        ->label('Link sub-incident')
                        ->icon('heroicon-o-link')
                        ->color('info')
                        ->form([
                            Forms\Components\Select::make('sub_incident_id')
                                ->label('Incident to link as a sub')
                                ->searchable()
                                ->required()
                                ->options(fn (?Incident $record) => SubIncidentLinkAction::options($record))
                                ->rule(fn () => fn (string $attribute, $value, \Closure $fail) => SubIncidentLinkAction::validatePick($value, $fail)),
                        ])
                        ->action(fn (Incident $record, array $data) => SubIncidentLinkAction::link($record, (int) $data['sub_incident_id']))
                        ->visible(fn (Incident $record): bool => auth()->user()->can('manage incidents')
                            && $record->classification === IncidentClassification::Incident
                            && blank($record->parent_incident_id)),
                    Tables\Actions\Action::make('unlink_sub_incident')
                        ->label('Unlink sub-incident')
                        ->icon('heroicon-o-link-slash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Unlink sub-incident')
                        ->modalDescription('The sub stays a standalone incident. Neither record is deleted.')
                        ->action(fn (Incident $record) => $record->update(['parent_incident_id' => null]))
                        ->visible(fn (Incident $record): bool => auth()->user()->can('manage incidents')
                            && filled($record->parent_incident_id)),
                    Tables\Actions\Action::make('downgrade_to_issue')
                        ->label('Downgrade to Issue')
                        ->icon('heroicon-o-arrow-down-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Downgrade to Issue')
                        ->modalDescription('This will reclassify this incident as an issue and assign a new Issue ID.')
                        ->action(function (Incident $record): void {
                            // PROJ-022: linked records keep the metrics honest —
                            // a mid-hierarchy reclassification would strand counts.
                            if ($record->hasIncidentLinks()) {
                                Notification::make()
                                    ->danger()
                                    ->title('Linked in a hierarchy')
                                    ->body('Unlink this incident from its super/sub-incidents before downgrading.')
                                    ->send();

                                return;
                            }

                            $record->changeClassification('Issue');
                        })
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
                        ->action(function (\Illuminate\Support\Collection $records): void {
                            $linked = $records->filter(fn (Incident $record) => $record->hasIncidentLinks());
                            $records->reject(fn (Incident $record) => $record->hasIncidentLinks())->each->changeClassification('Issue');

                            if ($linked->isNotEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title('Skipped linked incidents')
                                    ->body($linked->count().' selected incident(s) are linked in a hierarchy and were left unchanged — unlink them before downgrading.')
                                    ->send();
                            }
                        })
                        ->visible(fn (): bool => auth()->user()->can('manage incidents'))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->databaseTransaction()
                        ->visible(fn (): bool => auth()->user()->can('manage incidents')),
                ]),
            ])
            // PROJ-022 (owner 2026-11-16): super + subs render as ONE
            // highlighted group — the head carries its marker even collapsed
            // (the band signals a family), each sub carries its own; app.css
            // merges both into one continuous band (.sub-incident-family-head
            // / .sub-incident-row).
            ->recordClasses(function (Incident $record): array {
                if (filled($record->parent_incident_id)) {
                    return ['sub-incident-row'];
                }

                return $record->sub_incidents_count > 0 ? ['sub-incident-family-head'] : [];
            });
        // The list-page Summary strip (widget + footer view) was deleted in
        // main (owner 2026-11-20) — the merge carries that deletion.
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\StatusUpdatesRelationManager::class,
            RelationManagers\InvestigationDocumentsRelationManager::class,
            RelationManagers\InvestigationFormsRelationManager::class,
            RelationManagers\AuditsRelationManager::class,
            RelationManagers\ActionImprovementsRelationManager::class,
            RelationManagers\WarRoomSessionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncidents::route('/'),
            'board' => Pages\BoardIncidents::route('/board'),
            'create' => Pages\CreateIncident::route('/create'),
            'edit' => Pages\EditIncident::route('/{record}/edit'),
            'view' => Pages\ViewIncident::route('/{record}'),
        ];
    }

    public static function applyAccessControl(Builder $query): Builder
    {
        $query = $query->where('classification', '!=', IncidentClassification::Issue->value);

        return $query->applyUserYearAccess(auth()->user());
    }
}
