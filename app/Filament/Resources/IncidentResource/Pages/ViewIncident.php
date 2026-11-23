<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Filament\Actions\SubIncidentLinkAction;
use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use App\Notifications\IncidentNotDoneReminder;
use App\Services\Ai\PostMortemService;
use App\Services\Ai\SimilarIncidentService;
use App\Services\Markdown\IncidentMarkdownExporter;
use App\Support\ReminderMail;
use Filament\Actions;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewIncident extends ViewRecord
{
    protected static string $resource = IncidentResource::class;

    // Owner 2026-11-21: the heading is the record title — the default
    // "View {title}" prefix is gone (the breadcrumb already says View).
    public function getTitle(): string
    {
        return (string) $this->getRecord()->title;
    }

    public function mount(string|int $record): void
    {
        parent::mount($record);

        // Round 8: the investigation body self-refreshes on open when stale —
        // permission-gated here, quiet in the action.
        if (auth()->user()->can('manage incidents')) {
            \App\Filament\Actions\NotionInvestigationAction::autoRefresh($this->getRecord());
        }
    }

    private function isAiEnhanced($record, string $field): bool
    {
        $data = $record->ai_enhanced_fields;

        if (! $data) {
            return false;
        }

        $value = $data[$field] ?? null;

        if (is_array($value)) {
            return (bool) ($value['enhanced'] ?? false);
        }

        return (bool) $value;
    }

    private function aiBadge(string $field): TextEntry
    {
        return TextEntry::make("ai_badge_{$field}")
            ->label('')
            ->default('')
            ->html()
            ->formatStateUsing(function ($record) use ($field) {
                if (! $this->isAiEnhanced($record, $field)) {
                    return '';
                }

                return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#0d9488;background:#f0fdfa;border:1px solid #ccfbf1;border-radius:999px;padding:2px 10px;font-weight:600;">'
                    .'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8 19 13"/><path d="M15 9h.01"/><path d="M17.8 6.2 19 5"/><path d="m3 21 9-9"/><path d="M12.2 6.2 11 5"/></svg>'
                    .'AI Enhanced</span>'
                    .'<span style="display:inline-flex;align-items:center;gap:3px;font-size:10px;color:#94a3b8;margin-left:6px;">'
                    .'<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>'
                    .'AI-generated content may contain inaccuracies</span>';
            })
            ->visible(fn ($record) => $this->isAiEnhanced($record, $field));
    }

    protected function getHeaderActions(): array
    {
        return [
            // Owner 2026-11-21: seven header buttons squeezed the breadcrumb
            // and title into a narrow column. The header is now [Edit] + ONE
            // "three lines" kebab holding everything, plus Update Status and
            // Delete INSIDE it — the status can change without opening Edit.
            Actions\ActionGroup::make([
                Actions\Action::make('email_pic')
                    ->label('Email PIC')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Email PIC')
                    ->modalDescription('Send a reminder email to this incident’s PIC via Netcore.')
                    ->modalSubmitActionLabel('Send Email')
                    ->visible(fn ($record) => $record->pics->isNotEmpty() && $record->isNotDone() && auth()->user()->can('manage incidents'))
                    ->action(function () {
                        $incident = $this->getRecord();
                        $notification = new IncidentNotDoneReminder($incident);

                        $incident->pics->each(fn ($pic) => $pic->notify($notification));
                        // One combined email for all PICs (owner rule 2026-10-09).
                        $mailed = ReminderMail::send($incident->pics, $notification);

                        Notification::make()
                            ->when($mailed > 0, fn ($n) => $n->success()->title('Reminder sent')->body('Email sent to '.$incident->pics->pluck('email')->implode(', ').'.'))
                            ->when($mailed === 0, fn ($n) => $n->warning()->title('No email sent')->body(\App\Support\MailSettings::enabled()
                                ? 'No PIC has email reminders enabled in their preferences.'
                                : 'Email is turned off in Email Settings (global kill-switch).'))
                            ->send();
                    }),
                Actions\Action::make('detect_similar_incidents')
                    ->label('Detect Similar')
                    ->icon('heroicon-o-magnifying-glass-circle')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Detect similar incidents')
                    ->modalDescription('AI runs the full pipeline (think → find → verify → double-check). This may take up to a minute.')
                    ->modalSubmitActionLabel('Detect')
                    ->visible(fn (): bool => auth()->user()->can('manage incidents') && app(SimilarIncidentService::class)->isAvailable())
                    ->action(function () {
                        $incident = $this->getRecord();
                        $service = app(SimilarIncidentService::class);
                        $result = $service->analyze($incident);

                        if (! $result->success) {
                            Notification::make()
                                ->danger()
                                ->title('Detection failed')
                                ->body($result->error)
                                ->send();

                            return;
                        }

                        $service->persist($incident, $result->matches);

                        Notification::make()
                            ->success()
                            ->title('Similar incidents detected')
                            ->body(count($result->matches).' match(es) found — the list below is updated.')
                            ->send();
                    }),
                Actions\Action::make('generate_retro')
                    ->label($this->getRecord()?->retro_markdown ? 'Regenerate Retro' : 'Generate Retro')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Generate retrospective')
                    ->modalDescription('AI drafts a blameless retrospective from this incident and stores it on the record. Regenerating overwrites the stored retro.')
                    ->modalSubmitActionLabel('Generate')
                    ->visible(fn (): bool => auth()->user()->can('manage incidents'))
                    ->action(function () {
                        $incident = $this->getRecord();
                        $service = app(PostMortemService::class);
                        $markdown = $service->generateAsMarkdown($incident);

                        if ($markdown === null) {
                            Notification::make()
                                ->danger()
                                ->title('Retro generation failed')
                                ->body('The AI returned no usable content — nothing was stored.')
                                ->send();

                            return;
                        }

                        $incident->update([
                            'retro_markdown' => $markdown,
                            'retro_generated_at' => now(),
                            'retro_model' => $service->resolvedModel(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Retrospective generated')
                            ->body('The Retrospective section below is updated.')
                            ->send();
                    }),
                // PROJ-022: same hierarchy actions as the list page's row kebab,
                // for the record being viewed. Hidden on subs (one level deep).
                Actions\Action::make('create_sub_incident')
                    ->label('Create sub-incident')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->url(fn (Incident $record): string => IncidentResource::getUrl('create', ['parent' => $record->getKey()]))
                    ->visible(fn (Incident $record): bool => auth()->user()->can('manage incidents')
                        && $record->classification === IncidentClassification::Incident
                        && blank($record->parent_incident_id)),
                Actions\Action::make('link_sub_incident')
                    ->label('Link sub-incident')
                    ->icon('heroicon-o-link')
                    ->color('info')
                    ->form([
                        \Filament\Forms\Components\Select::make('sub_incident_id')
                            ->label('Incident to link as a sub')
                            ->searchable()
                            ->required()
                            ->options(fn (?Incident $record) => \App\Filament\Actions\SubIncidentLinkAction::options($record))
                            ->rule(fn () => fn (string $attribute, $value, \Closure $fail) => \App\Filament\Actions\SubIncidentLinkAction::validatePick($value, $fail)),
                    ])
                    ->action(function (Incident $record, array $data): void {
                        \App\Filament\Actions\SubIncidentLinkAction::link($record, (int) $data['sub_incident_id']);

                        Notification::make()
                            ->success()
                            ->title('Sub-incident linked')
                            ->body($this->getRecord()->subIncidents()->count().' sub-incident(s) linked to this record.')
                            ->send();
                    })
                    ->visible(fn (Incident $record): bool => auth()->user()->can('manage incidents')
                        && $record->classification === IncidentClassification::Incident
                        && blank($record->parent_incident_id)),
                // The export pair, flattened INTO the kebab (a dropdown cannot
                // nest inside another dropdown).
                Actions\Action::make('generate_post_mortem')
                    ->label('Post-Mortem PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Generate Post-Mortem Report')
                    ->modalDescription('AI will generate a comprehensive blameless post-mortem. This may take a few seconds.')
                    ->modalSubmitActionLabel('Generate & Download')
                    ->action(function () {
                        return redirect()->to(route('ai.post-mortem-pdf', ['incident' => $this->getRecord()->id]));
                    }),
                Actions\Action::make('export_markdown')
                    ->label('Export to Markdown')
                    ->icon('heroicon-o-document-arrow-down')
                    ->tooltip('Export this incident as a Markdown file for AI analysis')
                    ->action(function (IncidentMarkdownExporter $exporter) {
                        return $exporter->download($this->getRecord());
                    }),
                // Shared with the incidents-list kebab — one definition
                // (form + BUG-035 gate + write).
                \App\Filament\Actions\UpdateStatusAction::header(),
                Actions\DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('manage incidents')),
            ])
                ->iconButton()
                ->icon('heroicon-m-bars-3')
                ->color('gray')
                ->tooltip('More actions'),
            Actions\EditAction::make()
                ->visible(fn (): bool => auth()->user()->can('manage incidents')),
        ];
    }

    /** PROJ-022: wired from the Sub-incidents section rows' Unlink buttons. */
    public function unlinkSubIncident(int $id): void
    {
        abort_unless((bool) auth()->user()?->can('manage incidents'), 403);

        Incident::findOrFail($id)->update(['parent_incident_id' => null]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            ViewEntry::make('incident_hero')
                ->view('filament.resources.incident-resource.pages.incident-hero')
                ->columnSpanFull(),

            ViewEntry::make('recurrence_data')
                ->view('filament.resources.incident-resource.pages.recurrence-alert')
                ->visible(fn ($record) => filled($record->recurrence_data) && ($record->recurrence_data['is_recurring'] ?? false))
                ->columnSpanFull(),

            // PROJ-022: the hierarchy on the view page — a sub shows its
            // super as a "Sub of" chip; a super shows its subs with per-row
            // unlink. One level deep, so both never render together.
            Section::make('sub_of')
                ->heading('Sub of')
                ->icon('heroicon-o-arrow-up-left')
                ->iconColor('info')
                ->schema([
                    TextEntry::make('super_incident_link')
                        ->hiddenLabel()
                        ->badge()
                        ->color('info')
                        ->state(fn (Incident $record) => $record->superIncident ? "{$record->superIncident->no} — {$record->superIncident->title}" : null)
                        ->url(fn (Incident $record): ?string => $record->superIncident ? IncidentResource::getUrl('view', ['record' => $record->superIncident->getKey()]) : null),
                ])
                ->visible(fn (Incident $record) => filled($record->parent_incident_id))
                ->columnSpanFull(),

            Section::make('sub_incidents')
                ->heading(fn (Incident $record) => 'Sub-incidents ('.$record->subIncidents()->count().')')
                ->icon('heroicon-o-link')
                ->iconColor('info')
                ->schema([
                    ViewEntry::make('sub_incidents_list')
                        ->view('filament.resources.incident-resource.sub-incidents-list')
                        ->hiddenLabel(),
                ])
                ->footerActions([
                    InfolistAction::make('create_sub_incident_footer')
                        ->label('Create sub-incident')
                        ->icon('heroicon-o-plus-circle')
                        ->color('success')
                        ->url(fn (Incident $record): string => IncidentResource::getUrl('create', ['parent' => $record->getKey()]))
                        ->visible(fn (Incident $record): bool => (bool) auth()->user()?->can('manage incidents')),
                    InfolistAction::make('link_sub_incident_footer')
                        ->label('Link sub-incident')
                        ->icon('heroicon-o-link')
                        ->color('info')
                        ->form([
                            \Filament\Forms\Components\Select::make('sub_incident_id')
                                ->label('Incident to link as a sub')
                                ->searchable()
                                ->required()
                                ->options(fn (?Incident $record) => SubIncidentLinkAction::options($record))
                                ->rule(fn () => fn (string $attribute, $value, \Closure $fail) => SubIncidentLinkAction::validatePick($value, $fail)),
                        ])
                        ->action(fn (Incident $record, array $data) => SubIncidentLinkAction::link($record, (int) $data['sub_incident_id']))
                        ->visible(fn (Incident $record): bool => (bool) auth()->user()?->can('manage incidents')),
                ])
                ->collapsible()
                ->columnSpanFull()
                ->visible(fn (Incident $record) => $record->subIncidents()->exists()),

            Section::make('Summary')
                ->icon('heroicon-o-document-text')
                ->iconColor('primary')
                ->schema([
                    TextEntry::make('summary')
                        ->markdown()
                        ->hiddenLabel()
                        ->placeholder('No summary provided'),
                    $this->aiBadge('summary'),
                ])
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Root Cause Analysis')
                ->icon('heroicon-o-light-bulb')
                ->iconColor('warning')
                ->schema([
                    TextEntry::make('root_cause')
                        ->markdown()
                        ->hiddenLabel()
                        ->placeholder('No root cause analysis'),
                    $this->aiBadge('root_cause'),
                    TextEntry::make('root_cause_category')
                        ->label('Root Cause Category')
                        ->badge()
                        ->color('warning')
                        ->separator(','),
                    TextEntry::make('responsible_team')
                        ->label('Responsible Team')
                        ->badge()
                        ->color('dana')
                        ->separator(','),
                ])
                ->collapsible()
                ->columnSpanFull(),

            ViewEntry::make('similar_incidents')
                ->view('filament.resources.incident-resource.pages.similar-incidents')
                ->columnSpanFull(),

            Section::make('Retrospective')
                ->icon('heroicon-o-document-duplicate')
                ->iconColor('gray')
                ->schema([
                    TextEntry::make('retro_markdown')
                        ->markdown()
                        ->hiddenLabel(),
                    TextEntry::make('retro_generated_at')
                        ->label('Generated')
                        ->dateTime('Y-m-d H:i')
                        ->color('gray')
                        ->visible(fn ($record) => filled($record->retro_generated_at))
                        ->formatStateUsing(fn ($state, $record) => $state?->format('Y-m-d H:i').' · '.$record->retro_model),
                ])
                ->visible(fn ($record) => filled($record->retro_markdown))
                ->collapsible()
                ->columnSpanFull(),

            \App\Filament\Actions\NotionInvestigationAction::section(),

            Section::make('Incident Timeline & Chronology')
                ->icon('heroicon-o-clock')
                ->iconColor('info')
                ->schema([
                    TextEntry::make('timeline')
                        ->markdown()
                        ->hiddenLabel()
                        ->placeholder('No timeline recorded'),
                    $this->aiBadge('timeline'),
                ])
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Remark')
                ->icon('heroicon-o-chat-bubble-bottom-center-text')
                ->iconColor('dana')
                ->schema([
                    TextEntry::make('remark')
                        ->markdown()
                        ->hiddenLabel()
                        ->placeholder('No remarks'),
                    $this->aiBadge('remark'),
                ])
                ->collapsible()
                ->visible(fn ($record) => filled($record->remark))
                ->columnSpanFull(),

            Section::make('Labels')
                ->icon('heroicon-o-tag')
                ->iconColor('teal')
                ->schema([
                    TextEntry::make('labels.name')
                        ->label('Labels')
                        ->badge()
                        ->color('primary')
                        ->separator(','),
                ])
                ->visible(fn ($record) => $record->labels && $record->labels->isNotEmpty())
                ->columnSpanFull(),

            Grid::make(2)->schema([
                Section::make('Triage Details')
                    ->icon('heroicon-o-bolt')
                    ->iconColor('rose')
                    ->schema([
                        TextEntry::make('severity')
                            ->badge()
                            ->color(fn (?Severity $state) => $state?->color() ?? 'gray'),
                        TextEntry::make('incident_status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?IncidentStatus $state) => $state?->color() ?? 'gray'),
                        TextEntry::make('incident_date')
                            ->label('Incident Date')
                            ->dateTime('d M Y, H:i'),
                        TextEntry::make('discovered_at')
                            ->dateTime('d M Y, H:i'),
                        TextEntry::make('stop_bleeding_at')
                            ->label('Stop Bleeding At')
                            ->dateTime('d M Y, H:i'),
                        TextEntry::make('entry_date_tech_risk')
                            ->label('Entry Date Tech Risk')
                            ->date('d M Y'),
                    ])->columns(2)->columnSpan(1),

                Section::make('Financial Impact')
                    ->icon('heroicon-o-banknotes')
                    ->iconColor('amber')
                    ->schema([
                        TextEntry::make('fund_status')
                            ->badge()
                            ->color(fn (?FundStatus $state) => $state?->color() ?? 'gray'),
                        // PROJ-022 (owner 2026-11-16): family totals on a
                        // super — same display roll-up as the list money
                        // columns (stored values untouched).
                        TextEntry::make('potential_fund_loss')
                            ->money('IDR')
                            ->color('warning')
                            ->state(fn (Incident $record): float => $record->familyMoney()['potential']),
                        TextEntry::make('fund_loss')
                            ->label('Actual Fund Loss')
                            ->money('IDR')
                            ->color('danger')
                            ->state(fn (Incident $record): float => $record->familyMoney()['actual']),
                        TextEntry::make('recovered_fund')
                            ->money('IDR')
                            ->color('success')
                            ->state(fn (Incident $record): float => $record->familyMoney()['recovered']),
                        TextEntry::make('loss_taken_by'),
                    ])->columns(2)->columnSpan(1),
            ]),

            Grid::make(2)->schema([
                Section::make('Source & Assignment')
                    ->icon('heroicon-o-user-group')
                    ->iconColor('dana')
                    ->schema([
                        TextEntry::make('incident_source')
                            ->label('Source')
                            ->badge(),
                        TextEntry::make('pic_names')
                            ->label('PIC'),
                        TextEntry::make('reported_by'),
                        TextEntry::make('checker'),
                        TextEntry::make('maker'),
                        TextEntry::make('third_party_client')
                            ->label('3rd Party/Client'),
                    ])->columns(2)->columnSpan(1),

                Section::make('Categories & Metrics')
                    ->icon('heroicon-o-chart-bar')
                    ->iconColor('cyan')
                    ->schema([
                        TextEntry::make('business_category')
                            ->label('Business Category')
                            ->badge()
                            ->color('info')
                            ->separator(','),
                        TextEntry::make('classification')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('incident_type')
                            ->label('Area')
                            ->badge(),
                        TextEntry::make('incidentType.name')
                            ->label('Incident Type'),
                        TextEntry::make('mttr')
                            ->label('MTTR')
                            ->suffix(' mins'),
                        TextEntry::make('mtbf')
                            ->label('MTBF')
                            ->suffix(' days'),
                    ])->columns(2)->columnSpan(1),
            ]),

            Section::make('Admin & Flags')
                ->icon('heroicon-o-clipboard-document-check')
                ->iconColor('gray')
                ->schema([
                    TextEntry::make('goc_upload')->label('GoC Uploaded')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                    TextEntry::make('teams_upload')->label('Teams Uploaded')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                    TextEntry::make('doc_signed')->label('Doc Signed')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                    TextEntry::make('risk_incident_form_cfm')->label('Risk Form CFM')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                    TextEntry::make('glitch_flag')->label('Glitch')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->badge()->color(fn ($state) => $state ? 'danger' : 'gray'),
                ])
                ->columns(5)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Evidence')
                ->icon('heroicon-o-paper-clip')
                ->iconColor('yellow')
                ->schema([
                    TextEntry::make('evidence')
                        ->markdown()
                        ->hiddenLabel()
                        ->placeholder('No evidence text'),
                    TextEntry::make('evidence_link')
                        ->label('Evidence Link')
                        ->url(fn ($state) => $state)
                        ->openUrlInNewTab(),
                ])
                ->collapsible()
                ->visible(fn ($record) => filled($record->evidence) || filled($record->evidence_link))
                ->columnSpanFull(),
        ]);
    }
}
