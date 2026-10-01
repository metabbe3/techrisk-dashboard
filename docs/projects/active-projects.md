# Active Projects

*This document tracks all ongoing and planned projects for the Technical Risk Dashboard.*

---

## Project Template

```markdown
### [PROJ-XXX] Project Name

**Status:** [Backlog / In Progress / In Review / Done]
**Priority:** [P1 / P2 / P3 / P4]
**PM:** [Name]
**Assigned Agents:** [List of agents/team members]
**Start Date:** YYYY-MM-DD
**Target Completion:** YYYY-MM-DD

#### Description
[Brief description of project goals and business value]

#### Technical Approach
- [Architecture decisions]
- [Key technologies]
- [Dependencies]

#### Tasks
- [ ] Task 1 - [Agent] - [Status]
- [ ] Task 2 - [Agent] - [Status]
- [ ] Task 3 - [Agent] - [Status]

#### Dependencies
- Dependency 1
- Dependency 2

#### Blockers
- [Blocker if any, otherwise write "None"]

#### Progress Updates
- YYYY-MM-DD: Update description
- YYYY-MM-DD: Update description
```

---

## Active Projects

### [PROJ-001] Initial SOP and Documentation Setup

**Status:** Done
**Priority:** P1
**PM:** Claude (PM Agent)
**Assigned Agents:** PM
**Start Date:** 2026-02-02
**Target Completion:** 2026-02-02

#### Description
Establish Standard Operating Procedures (SOP) and documentation structure for the Technical Risk Dashboard project to ensure consistent development practices and continuous improvement.

#### Technical Approach
- Created CLAUDE.md with comprehensive SOPs for all roles
- Established docs/ folder structure for projects, bugs, and findings
- Implemented PM-centralized workflow for all requests
- Created templates for lesson learned and project tracking

#### Tasks
- [x] Create CLAUDE.md with role-based SOPs - PM - Done
- [x] Create docs/ folder structure - PM - Done
- [x] Create active-projects.md template - PM - Done
- [x] Create lesson-learned.md template - PM - Done
- [x] Create findings.md template - PM - Done

#### Dependencies
- None

#### Blockers
- None

#### Progress Updates
- 2026-02-02: Project completed. Documentation structure established.

---

## Backlog

### [PROJ-005] AI Agents — user-defined scheduled agents (trigger.dev-style)

**Status:** In Progress
**Priority:** P2
**Start Date:** 2026-09-18

#### Description
New 'AI' nav group with an Agents CRUD resource: admins create text-only AI agents (name, instructions = system prompt, optional model override — null falls through to the AI Settings default model), scheduled via 5-field cron (`ai:dispatch-due-agents`, every minute) or run manually. Every run is observable in a read-only Agent Runs feed (status, model, tokens, latency, error, input/output snapshots) and is logged to `ai_usage_logs` with `field_type = 'agent'`. Reuses `AiTextService` (new `generate()`), `CircuitBreaker`, and the existing default-model setting.

#### Tasks
- [x] Migrations, enums, models, `generate()`, job, dispatch command, schedule — 2026-09-18
- [x] Filament AiAgentResource + AiAgentRunResource; AI Settings/AI Usage Logs moved to 'AI' group — 2026-09-18
- [x] Phase 2: dashboard-context injection, output delivery (email), run retention pruning, easier creation (templates, schedule presets, health-badged model picker) — 2026-09-21
- [x] Phase 3: agent collaboration — chain runs (depends_on), shared memory (source of truth), org chart (reports_to) — 2026-09-21
- [ ] Phase 4: tool use (allow-list over the existing tool registry), human-approval gates, self-evolving loops

#### Blockers
None

#### Progress Updates
- 2026-09-18: Project created; Phase 1 (text-only agents) implemented.
- 2026-09-21: Phase 2 shipped. `include_context` appends `ChatContextService::getQuickStats()` to the run prompt; `notify_email` receives output/error via `Mail::raw` (best-effort, never fails the run); runs pruned after 90 days (`AI_AGENT_RUN_RETENTION_DAYS`, `model:prune` daily 02:45). Create form: template presets (`AiAgentTemplates`), friendly schedule (Manual/Daily/Hourly/Weekly/Custom-cron — translated by `AiAgentResource::resolveScheduleData()` + `AiAgent::cronFromPreset()`/`schedulePresetFromCron()`, DB still stores only frequency+cron), model picker now `getModelsForPicker()` (hides unhealthy models). 8 new tests; suite 559 with unchanged pre-existing baseline. Tool use intentionally still text-only ceiling.
- 2026-09-21: Phase 2b shipped — "Draft with AI" creation. New create-only form section: describe the agent in one sentence → `AiAgentDraftService` (`callAiForJson('agent_draft')`, config `ai.prompts.agent_draft.system`) returns whitelisted/normalized name/description/instructions/include_context/schedule-preset, and the hintAction `$set`s all fields for review before save. 6 new tests (suite 565, baseline unchanged). Decision: Retrospective Agents (War Room personas — manual, multi-round, tool-using) stay a separate runtime from scheduled AI Agents; nav converged only — `WarRoomAgentConfigResource` moved to the 'AI' group (sort 92) so all agent management lives together.
- 2026-09-21: Phase 3 shipped — agent collaboration, three mechanisms. **Chaining:** `depends_on_agent_id` ("Runs after") — on success the job dispatches every enabled dependent via `dispatchRun(triggeredByRunId:)`; runs store `triggered_by_run_id` provenance; `AiAgent::createsDependencyCycle()` guards both columns at form level (self-select excludes self; walk-up visited-set; X-after-Y when Y-after-X is correctly a cycle). **Shared memory (one source of truth):** `include_memory` agents append `AiAgentMemoryService::PROTOCOL` to the system prompt; on success `extractFromOutput()` parses the trailing `## Memory` section (`- LESSON:/- OUTCOME:/- NOTE:`, tolerant of case/bold/CRLF, ends at next heading) into `ai_agent_memories` (kind enum, `run_id` **nullOnDelete** — lessons survive the 90-day run prune); `recentForPrompt()` injects newest 25 (`memory_inject_limit`) back into prompts. New read/delete **Agent Memory** resource (light-bulb icon, AI group). **Org chart:** `reports_to_agent_id` tree rendered as indented text into every run prompt when any hierarchy exists (orphan roots render, corrupt-DB cycles guarded); works for company org and SDLC pipelines (a pipeline = a depends_on chain). Teamwork section in agent form (cycle-validated selects + memory toggle). Text-only ceiling kept: no tool calls, memory writes are parsed output. 21 new tests (suite 586, baseline 18F+17E unchanged — auth/notification/export only). Live-verified: A→B chain ran, B's output quoted A's injected lessons and wrote its own OUTCOME back. Demo agents "Chain A — Digest"/"Chain B — Follow-up" left in place.
- 2026-09-21: Phase 3 addendum — teamwork fields are now draftable. "Draft with AI" passes the enabled-agent list (`id => name`) into the draft prompt; the model returns `runs_after`/`reports_to` ids which `AiAgentDraftService::resolveAgentId()` keeps only when they exist (hallucinated ids/names drop to null — no cycle check needed since a create-form agent can't close a loop); `include_memory` defaults on when either link resolves. The action `$set`s all three Teamwork fields, so one sentence fills the whole form including the chain. 4 new tests (suite 590, baseline unchanged). Live-verified: "a follow-up risk reviewer that runs after Chain A — Digest…" resolved both links and memory=true.
- 2026-09-21: Phase 3 addendum 2 — **human feedback loop** (Hermes-style learning). New "Give feedback" action on completed runs in Agent Runs (warning-colored, modal with required 1000-char textarea) → `AiAgentMemoryService::recordFeedback()` stores a `Feedback` memory row tied to the run (`run_id` nullOnDelete survives pruning; Auditable records who). Feedback flows into future prompts through the existing `recentForPrompt()` injection — zero new injection code, no migration (kind is a string column; `AiAgentMemoryKind` gained `Feedback` → warning). Integrity for free: `extractFromOutput()` only parses lesson/outcome/note bullets, so agents can never forge Feedback rows — Feedback ⟺ human-written. 3 new tests (suite 593, baseline unchanged), incl. first Livewire table-action tests in the repo. Live-verified: feedback "keep the digest under 5 bullets and name the top severity incident" → next run produced exactly 5 bullets with "Four P1 incidents (highest severity)" as bullet one.
- 2026-09-21: Phase 3 addendum 3 — **flow-failure audit fixes** (full audit in `docs/findings/findings.md` AG-1..8). (1) Chain handoff: dependent runs now inject `## Upstream result from "{agent}"` — the triggering run's actual output (`upstream_inject_limit` 4000 chars). (2) Safe retry: one retry (3s backoff) around the generation call only; side effects stay exactly-once. (3) Output contract: new `expected_output` + `require_json` columns, "Output contract" form section, `## Expected output` prompt block, JSON validation with one repair retry feeding the parse error back (twice-invalid → Failed, chain correctly dies); both fields draftable. 9 new tests (suite 602, at/below baseline). Live-verified: B's output reviewed A's exact digest figures; require_json agent returned `{"verdict":"high","open_count":7,...}`.
- 2026-09-21: Phase 3 addendum 4 — **guided workflows + agent file reading** (Dify-style builder, scoped per user choice to guided steps + visual map — NOT a canvas editor). A workflow is an ordered agent list (`ai_workflows`/`ai_workflow_steps`, uuid, `unique(agent_id)` = one workflow per agent); `AiWorkflowService::saveSteps()` renders it into the existing `depends_on` chain — no new executor, chain propagation + provenance come free. Filament "Workflows" resource (AI group, gated `manage api tokens`): Repeater step builder (native drag-reorder), View page with Mermaid flow diagram (existing page-scoped `resources/js/mermaid.js`, sanitized labels, escaped fallback) + Run-workflow header action (dispatches step 1; `healChain` bridges gaps when an agent is deleted). Agent "Files & documents" section: uploads (pdf/docx/xlsx/txt/md/csv/json ≤15MB, finfo content-sniff — same whitelist as chat attachments) extracted at attach time via `DocumentConverterService::convertRaw` into `ai_agent_files.extracted_text`; runs inject `## Attached files` (`file_inject_limit` 8000) and, when `include_documents` is on, `## Investigation documents` from the 5 most recent investigation docs (best-effort skip). 12 new tests (suite 613). Two uuid-keyed-collection bugs caught before ship (mermaid node ids, step i−1 lookup) + Filament 3 has no `view-record` layout — custom pages wrap `<x-filament-panels::page>`. Roadmap: canvas/node-graph editor, path/SFTP/remote file sources, per-run file selection. Live: "Nightly Risk Pipeline" (Chain A→B) wired, run dispatched through the real queue (dev gateway env is intentionally unset → failed cleanly at the HTTP call, B correctly not dispatched), .txt/.csv attached + extracted on disk.
- 2026-09-21: Phase 3 addendum 5 — **post-ship fix (BUG-015)**: `/admin/ai-workflows` 500'd live because `AiWorkflow`/`AiWorkflowStep`/`AiAgentFile` used the auditing trait without `implements Auditable` — invisible to phpunit because `audit.console=false` means the observer never attaches in console processes. Fixed all 3 (AiAgent pattern); regression test attaches the observer explicitly and hits the index route over HTTP (RED-verified against the exact production TypeError). Suite 614, known band unchanged.

### [PROJ-003] Export Incident Reports to Markdown

**Status:** Backlog
**Priority:** P2
**PM:** TBD
**Assigned Agents:** TBD (backend-architect-engineer, frontend-engineer)
**Start Date:** TBD
**Target Completion:** TBD

#### Description
Implement a Markdown export feature for individual incident reports. The export will be AI-consumable with well-structured, clean markdown that includes all relevant incident data (summary, timeline, root cause, financial impact, action items, etc.). This will enable AI analysis of incident patterns and facilitate automated reporting.

#### Technical Approach
- Create a dedicated Markdown export service in `app/Services/Markdown/`
- Add Filament action on Incident view page
- Use Laravel Blade templates for markdown generation
- Implement proper formatting for AI consumption (structured headers, consistent data formats)
- Include all relationships: StatusUpdate, InvestigationDocument, ActionImprovement, Label, incidentType
- **See detailed technical plan:** `docs/projects/markdown-features-technical-plan.md`

#### Tasks
- [ ] Phase 1.1: Create MarkdownExportService base class - backend-architect-engineer - Backlog
- [ ] Phase 1.2: Create IncidentMarkdownExporter service - backend-architect-engineer - Backlog
- [ ] Phase 1.3: Create resources/views/markdown/incident.blade.php template - frontend-engineer - Backlog
- [ ] Phase 1.4: Add export_markdown action to ViewIncident page - backend-architect-engineer - Backlog
- [ ] Phase 1.5: Create tests for markdown export format - backend-qa-engineer - Backlog
- [ ] Phase 1.6: Manual testing with various incident data - frontend-qa-specialist - Backlog
- [ ] Phase 1.7: Documentation update - backend-architect-engineer - Backlog

#### Dependencies
- None

#### Blockers
- None

#### Progress Updates
- 2026-02-02: Project added to backlog. Technical requirements defined. Detailed plan created in markdown-features-technical-plan.md.

---

### [PROJ-004] Convert Uploaded Documents to Markdown

**Status:** Backlog
**Priority:** P2
**PM:** TBD
**Assigned Agents:** TBD (backend-architect-engineer, backend-qa-engineer)
**Start Date:** TBD
**Target Completion:** TBD

#### Description
Automatically convert uploaded PDF/DOC files to Markdown format when they're attached to incidents. This makes documents searchable and AI-consumable, enabling advanced analysis and search capabilities across incident documentation.

#### Technical Approach
- **Recommended packages:**
  - PDF: `iamgerwin/php-pdf-to-markdown-parser` (lightweight, dedicated PDF to Markdown)
  - DOCX: `phpoffice/phpword` (well-established, robust DOCX parsing)
- **Alternative:** Doxswap (LibreOffice-based) - NOT recommended due to server dependency
- Create DocumentConverterService in `app/Services/Markdown/`
- Implement queue-based async conversion (ConvertDocumentToMarkdown job)
- Add markdown_path, markdown_converted_at, markdown_conversion_status to investigation_documents table
- Handle encrypted files (decrypt → convert → store markdown → re-encrypt if needed)
- Add Filament actions for viewing/downloading converted markdown
- **See detailed technical plan:** `docs/projects/markdown-features-technical-plan.md`

#### Database Schema Changes
```php
// Add to investigation_documents table
$table->string('markdown_path')->nullable();
$table->timestamp('markdown_converted_at')->nullable();
$table->string('markdown_conversion_status')->default('pending');
// Values: pending, processing, completed, failed
```

#### Storage Strategy
- Converted markdown: `storage/app/markdown/documents/{document_id}.md`
- Temp files during conversion: `storage/app/temp/` (auto-cleanup)

#### Tasks
- [ ] Phase 2.1: Create database migration for markdown fields - backend-architect-engineer - Backlog
- [ ] Phase 2.2: Install composer packages (iamgerwin/php-pdf-to-markdown-parser, phpoffice/phpword) - backend-architect-engineer - Backlog
- [ ] Phase 2.3: Create DocumentConverterService - backend-architect-engineer - Backlog
- [ ] Phase 2.4: Create ConvertDocumentToMarkdown queue job - backend-architect-engineer - Backlog
- [ ] Phase 2.5: Update InvestigationDocumentsRelationManager to dispatch job - backend-architect-engineer - Backlog
- [ ] Phase 2.6: Create Filament actions for viewing/downloading markdown - frontend-engineer - Backlog
- [ ] Phase 2.7: Create temp storage directory and configure cleanup - backend-architect-engineer - Backlog
- [ ] Phase 2.8: Create tests for conversion service - backend-qa-engineer - Backlog
- [ ] Phase 2.9: Manual testing with PDF and DOCX files - backend-qa-engineer - Backlog
- [ ] Phase 2.10: Error handling and retry logic testing - backend-qa-engineer - Backlog

#### Dependencies
- None (can be developed in parallel with PROJ-003)

#### Blockers
- None

#### Risks & Considerations
- **Medium Risk:** PDF/DOCX conversion quality may vary
  - Mitigation: Provide manual review process and reconvert option
- **Medium Risk:** Performance issues with large files
  - Mitigation: Queue processing, file size limits (15MB already enforced)
- **Low Risk:** Package compatibility issues
  - Mitigation: Thorough testing, both packages are actively maintained

#### Progress Updates
- 2026-02-02: Project added to backlog. Technical requirements defined. Detailed plan created in markdown-features-technical-plan.md.

---


### [PROJ-005] Export Redesign — Executive Report, Group By, Form Validation

**Status:** Done
**Priority:** P1
**PM:** Claude (PM Agent)
**Assigned Agents:** PM, backend-architect-engineer, backend-qa-engineer
**Start Date:** 2026-09-29
**Target Completion:** 2026-09-29

#### Description
Rework the incident Export button from a single all-tabs dump into a 4-preset workflow (Executive Report with native Excel charts + KPI cards, Group By dimension sheets with per-group MTTR/MTBF, All Tabs, Custom), add optional export filters, and harden the incident form against bad data (negative funds, reversed timelines, future dates).

#### Technical Approach
- `App\Filament\Actions\ExportActionSchema` — single source for form schema, `applyFilters()`, `columnOptions()`
- `App\Exports\ExecutiveIncidentsExport` + `Sheets/Executive{Data,Calc,Summary}Sheet` — KPI + 4 native charts; post-write XML pass injects chart `numCache`/`strCache` (PhpSpreadsheet omits them; blank charts in Numbers/QuickLook otherwise)
- `App\Exports\GroupedIncidentsExport` + `Sheets/GroupSummarySheet` + `Sheets/PerCategorySheet` — per-dimension sheets, multi-category incidents appear in each
- Shared stats via `App\Filament\Statistics\IncidentStatsFooterData` (also powers the table footer)
- Form validation: `minValue(0)` on fund fields, `maxDate(now())` on incident/entry dates, `afterOrEqual('incident_date')` on discovered/stop-bleeding (found via live HTTP-submit QA matrix)

#### Tasks
- [x] Preset form + handler — backend — Done
- [x] Executive report with charts + cache injection — backend — Done
- [x] Group By export (6 dimensions) — backend — Done
- [x] Group By: + Quarter dimension (Q1–Q4 by incident_date, "2026-Q1" value / "Q1 2026" label, year-aware) — backend — Done (2026-09-30)
- [x] Exports: MTTR plain minutes everywhere + G / Non Incident rows excluded from all presets (Non Incident tab sheet removed, All Tabs now 14 sheets) — backend — Done (2026-09-30)
- [x] Group-By export: Summary widget-aligned (Cases/Actual/Potential follow dashboard card rules) + "Excluded - <FundStatus>" tabs for the difference rows — backend — Done (2026-09-30)
- [x] Quarter multi-select filter (f_quarter) for every preset + QuarterRange concern — backend — Done (2026-09-30)
- [x] Rupiah as native Excel format on every fund cell (IdrFormat concern, all 4 presets) — backend — Done (2026-09-30)
- [x] Executive report widget-aligned (computeKpi single source) + per-quarter Q1–Q4 KPI tabs with severity row (P1–P4 + combined X1–X4) — backend — Done (2026-09-30)
- [x] MTBF reconciliation in All-Tabs/Custom exports: first-of-year cell `-` (no Jan-1 anchor), bottom Avg MTBF = mean of displayed column (equals widgets' span/(n−1)) — backend — Done (2026-09-30)
- [x] Workbook audit round 2 (owner's downloaded file): MTBF sequence caches static→instance (stale FPM workers dashed out new incidents mid-year), Issues-MTBF sheet reconciled like the others, day-only-MTTR tabs show `-` Avg MTTR — backend — Done (2026-09-30)
- [x] Optional export filters — backend — Done
- [x] Form validation hardening (4 gaps found live) — backend — Done
- [x] PSR-4 refactor: one class per file — backend — Done
- [x] Full suite regression — QA — Done (529/1765 green)

#### Dependencies
- maatwebsite/excel 3.1 (WithCharts), phpoffice/phpspreadsheet chart writers

#### Blockers
None

#### Progress Updates
- 2026-09-29: Shipped in commits 6d783c4, 45abd9b, bf40c08. Charts verified in Excel/Numbers via cached values; QA matrix re-run post-fix (all invalid inputs blocked).

---

### [PROJ-006] Outlier Label — exclude from MTBF/MTTR math, keep in counts

**Status:** In Progress (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner rule 2026-10-01: an Incident/Issue tagged Label `Outlier` drops out of every MTBF/MTTR average — calculator nulls its stored metrics, every metric query/span composes `Incident::withoutOutliers()`, predecessor gaps telescope over it — while staying in every count (Total Cases, status tabs, severity breakdowns, KPI counts, Group-By Cases) and every table/export row. Its own MTTR/MTBF cells render the literal `Outlier`. Tagging is a metrics trigger via the new `IncidentLabel` pivot (dispatches recalc + bumps `dashboard_cache_version`); `autoLabel()` never infers it from text. Bonus fixes shipped in the same commit (BUG-024): `app:send-report` fatalled on a private method, its metrics matched 0 rows (enum trap), Reporting page 500'd on enum echo, two unversioned 15-min caches (`mttr_mtbf_trend` → v5, `analytics` → v3).

#### Tasks
- [x] Model primitives (`Label::OUTLIER`, `withoutOutliers()`, `isOutlier()`) — 2026-10-01
- [x] Calculator + job: outlier guards in all 4 methods, predecessor/find-next queries scoped — 2026-10-01
- [x] Freshness: `IncidentLabel` pivot dispatch + autoLabel guard — 2026-10-01
- [x] Query surfaces: footer, dashboard/incident widgets, trend chart, AI insights/controller, Reporting, SendReport, Analytics choke point — 2026-10-01
- [x] Presentation: `mttr_formatted` accessor, sequence sheets ×3, stored sheets ×2 (null→0), live tables ×2, Issues-sheet row retention, markdown truthy fix — 2026-10-01
- [x] Docs: CLAUDE.md dependency map + business rules, BUG-024 — 2026-10-01
- [ ] Owner live check after deploy: tag an incident Outlier → cells show `Outlier`, averages shift, counts don't; detach restores

---

### [PROJ-007] Export Markdown ZIP — AI-consumption corpus

**Status:** Done (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner request 2026-10-01: every incident in the current table view becomes a folder holding its full markdown report plus every attached investigation document converted to markdown; all zipped for feeding AI tools. Markdown-only zip, Incidents only (no Issues), P1–P4/X1–X4 via `ExportActionSchema::baseExportScope()` (extracted as the single source; `applyFilters()` now calls it). Unconvertible/failed documents are skipped with an in-band note in the incident's md — the corpus is honest about gaps. Builds on PROJ-003's exporter + PROJ-004's converter (cached-first, per the `ai_summarize` precedent). Completes PROJ-003's vision. Content assembly now lives in `IncidentMarkdownCorpusService` (PROJ-008); the zip service is a thin orchestrator over it.

#### Tasks
- [x] `baseExportScope()` extraction + regression guard — 2026-10-01
- [x] `IncidentMarkdownZipService` (index.md, sanitized/deduped names, cached-first conversion, skip-and-note appendix) — 2026-10-01
- [x] `export_markdown_zip` header action on ListIncidents — 2026-10-01
- [x] Docs (CLAUDE.md export architecture item 5) — 2026-10-01
- [ ] Owner live check after deploy: Incidents → Export Markdown ZIP → zip with one folder per incident + index.md

---

### [PROJ-008] Incident Memory — persistent corpus for all Agents/AI

**Status:** Done (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner request 2026-10-01: one button holding "memory of all incidents" every Agent/AI can read, auto-updating with data changes. Implemented as a persistent corpus at `storage/app/markdown/corpus/` (`index.md` catalog + one sanitized folder per incident: full report + converted documents) built by `IncidentMarkdownCorpusService` — content assembly moved there from the zip service (byte-identical output; zip tests guard the refactor). Scope: Incidents + `baseExportScope()` (P1–P4/X1–X4; fund-status-excluded rows stay — knowledge base, not metrics). Rebuild = "Rebuild Incident Memory" button on the Agent Memory page + hourly `incidents:refresh-corpus` (stale-check: scoped count / `dashboard_cache_version` / incident+doc `updated_at`, second-precision, `>=`). AI consumption v1: `include_corpus` agent toggle injects the catalog (`## Incident catalog`, capped `ai.agents.corpus_inject_limit` 6000) into every run; `catalog()` is the long-term-memory API chat/WarRoom can adopt later. Rendered deterministically — zero AI calls, zero tokens to build; models only matter when an agent reads it.

#### Tasks
- [x] `IncidentMarkdownCorpusService` (refresh/catalog/isStale/index/folderFor/incidentFiles) + zip refactor — 2026-10-01
- [x] `incidents:refresh-corpus {--force}` command + hourlyAt(17) schedule — 2026-10-01
- [x] `include_corpus` migration + model + config + RunAiAgentJob catalog block — 2026-10-01
- [x] Agent form toggle + Corpus icon column + Agent Memory rebuild button — 2026-10-01
- [x] Docs (CLAUDE.md dependency map + cache keys) — 2026-10-01
- [x] Visibility fix (owner live-check feedback 2026-10-01: rebuild succeeded — 115 incidents — but nothing persisted on the page): `manifest()` accessor, `IncidentMemoryStatusWidget` (count · built · Fresh/Stale, live-updates via `incident-memory-rebuilt` dispatch from the rebuild action — Filament widgets are lazy-isolated children), `view_incident_catalog` read-only modal (index.md content) — 2026-10-01
- [ ] Owner live check after deploy: Agent Memory page shows status panel (115 incidents · Fresh) + View catalog lists incidents; rebuild press updates the panel without reload; agent with toggle → output references catalog incidents

---

### [PROJ-009] Similar Incidents (accurate + automatic) + Retro generation

**Status:** Done (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner request 2026-10-01: similar-incident detection "is not accurate" (irrelevant matches on the incident pages), must appear automatically with a score on every new incident, plus on-demand detection and AI retro drafting ("Restro") using the incident memory. Root causes found and fixed (all in `SimilarIncidentService`): RC1 verify saw only 800 chars/candidate → now the full `generateCompact()` report capped `ai.similarity.verify_context_chars` (2500); RC2 verify message anchored on junk RAG scores ("Rank #N · retrieval X") → removed; RC3 structured-only taxonomy twins entered verify with zero textual evidence → dropped when RAG has hits; RC4 any pipeline hiccup silently fell back to the loose legacy single-call prompt → now opt-in (`ai.similarity.legacy_fallback`, default false; legacy-path enum crash fixed too); RC5 `match_type` stored but never displayed → Deep/Thematic badge on edit card + view section. Auto-detection: `IncidentObserver::created` dispatches `DetectSimilarIncidentsJob` (2-min delay, isAvailable-gated) → `analyze()` + `persist()` (persist extracted from the controller as the single home of re-verify semantics: prune stale auto rows, keep admin-dismissed, re-activate re-detected). View page: "Similar Incidents" section after Root Cause Analysis (link/severity/status/similarity %/badge/reason, hint when empty) + Detect Similar header action. Retro: `generate_retro` action stores `retro_markdown`/`retro_generated_at`/`retro_model` (`PostMortemService::generateAsMarkdown()` over the untouched `generate()` array; null → error notification) + Retrospective section rendering markdown. **Prod note: the retro_* migration is an owner-only guardrail.**

#### Tasks
- [x] Part A accuracy fixes A1–A5 (compact verify context, anchor removal, taxonomy-twin filter, opt-in legacy fallback, match-type badge) — 2026-10-01
- [x] Part B persist() extraction + auto-detect job off the observer — 2026-10-01
- [x] Part C ViewIncident Similar Incidents section + Detect Similar action — 2026-10-01
- [x] Part D retro columns + generateAsMarkdown + action + section — 2026-10-01
- [x] Part E CLAUDE.md dependency map (SimilarIncidentService + PostMortemService rows) + this entry — 2026-10-01
- [ ] Owner live check after deploy: known repeat incident → Detect Similar → reasons cite root-cause substance + Deep/Thematic badges; new incident → section auto-populates ~2 min later; Generate Retro → section + PDF

---

---

## Completed Projects

### [PROJ-005] Export Redesign — Executive Report, Group By, Form Validation
- **Completed:** 2026-09-29
- **Outcome:** 4-preset export (Executive/GroupBy/AllTabs/Custom) + filters + validation hardening; 529/1765 green

### [PROJ-001] Initial SOP and Documentation Setup
- **Completed:** 2026-02-02
- **Outcome:** Established documentation framework for continuous improvement

---

*Last Updated: 2026-10-01*
