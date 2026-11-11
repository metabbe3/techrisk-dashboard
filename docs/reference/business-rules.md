# Business Rules & Validation (confirmed with owner)

Full detail of every counting/metric/fund rule. CLAUDE.md carries only the headline versions.

## Metrics & counts

- MTTR positive = minutes, negative = elapsed calendar days, time-of-day ignored, **no `+1`** (owner rule 2026-10-13: 02→09 Oct = 7, not 8; same-day fund-loss resolution stores 0 and joins the minutes bucket; pre-2026-10-13 rows need a metrics recalc).
- Fund Loss card = classification `Incident` + status `Completed` + `excludedFromCounts`.
- "Open cases" = `incident_status != Completed`.
- Incident COUNTS exclude severity `G` and `Non Incident` everywhere (2026-09-17 product rule — dashboard cards, AI quick stats, WarRoom tools, trends all use `aiCounts()`/`countEligible()`; operator-filtered table footers and Reporting/SendReport queries are row-counts of their own filtered sets and keep counting what they list).
- Severity BREAKDOWNS (Group-By severity sheets, executive Severity Mix chart) are likewise P1–P4 + X1–X4 only (owner rule 2026-09-29, BUG-022) — and enum-cast attributes compared on Collections must go through `?->value` (enum == string is always false in PHP 8).
- Base MTBF = calendar year window.
- Export MTBF sequences follow the export's `f_*` filters (owner report 2026-10-23) — filtering Fund Status to **Confirmed loss + Non fundLoss** reproduces the owner's hand-made report (~7,6 days for 2026 vs the unfiltered chain's ~3,8; both arithmetically correct, different scopes). Gaps telescope over filtered-out rows; the footer = mean of the scoped column. The owner's sheet also hand-skips 4 predecessor pairs (Waffo Jun 3, TORU Jul 12 — merchant non-system-failure cases); no system rule covers that yet.
- Export BASELINE is widget-aligned by default (owner rule 2026-10-23, amended 2026-10-27): All Cases / Completed / quarter tabs, Custom and Executive data count **Confirmed loss + Non fundLoss + null/empty fund_status** — the total tile's `excludedFromCounts` scope (a null fund_status counts on the tile, so it exports; the original strict-`IN` baseline dropped such rows — prod 2026: widget 39, export 38, the widget correct by owner ruling). An explicit Fund Status filter in the export form overrides the default and stays strict (null excluded); dedicated fund-status tabs (and Recovered Cases / On Going / P4 / Non-Tech / Issues / Group By / Markdown ZIP) keep their full row sets.
- Known remaining divergence: the live table's `mtbf_display` column still anchors first-of-year at `dayOfYear`.

## Sub-incident roll-up (PROJ-022, owner-approved 2026-11-11)

A **super-incident** is a main incident with sub-incidents linked to it; a **sub-incident** is a separate full incident record whose `parent_incident_id` points at its super. One level deep (a sub can never have subs, and a linked record can't be linked again). Issues are untouched.

- **Counts roll up into the super.** Sub-incidents are excluded from every incident-COUNT surface: dashboard cards/charts (dimension charts roll subs into their supers), weekly counts, Reporting/SendReport listings, exports. Implemented as `countEligible(withSubIncidents: false)` / `aiCounts(withSubIncidents: false)` defaults + `withoutSubIncidents()` (`whereNull('parent_incident_id')`) — a new count surface MUST exclude subs.
- **Money stays with subs.** The Fund Loss tile/trend/Pareto keep counting sub fund loss — money surfaces opt in with `withSubIncidents: true`.
- **List:** subs are hidden unless expanded or surfaced — search, tabs, filters, `?ids=` drill-in all surface them; an expanded family sorts super→subs by adjacency. Issues/board are unaffected (board shows only chips).
- **Exports exclude subs entirely** (all 5 presets + Markdown ZIP) — via `baseExportScope()`; the **AI corpus keeps them** (knowledge base).
- **Reporting/SendReport mirror the dashboard** (subs rolled up, money kept).
- **Classification guard:** a record linked in a hierarchy (sub of a super, or a super with subs) cannot change classification Incident ↔ Issue until unlinked — edit-form rule, `downgrade_to_issue` and `bulk_downgrade` refuse with a notification. `hasIncidentLinks()` is the predicate.
- **Link semantics are MOVE:** `parent_incident_id` is a single column — linking a linked record means unlink first. Deleting a super orphans its subs (`nullOnDelete`), never deletes them.
- **Unlink never deletes:** every unlink path (list row action, view page, board) just sets `parent_incident_id = null`; both records stay.
- **Cache freshness:** `parent_incident_id` is in the observer's dirty-field trigger list — a link change recalcs metrics and bumps `dashboard_cache_version`.

## Outlier rule (owner 2026-10-01)

A Label `Outlier` tag removes the row from every MTBF/MTTR *average* (calculator nulls its stored metrics; every metric query/span composes `withoutOutliers()`; predecessor gaps telescope over it so Σgaps still equals span) while it stays in every *count* (Total Cases, status tabs, severity breakdowns, KPI counts, Group-By Cases) and every table/export row — its own MTTR/MTBF cells render the literal **`Outlier`** (`mttr_formatted` accessor + per-surface mtbf cells; live-table `state()` returns `int|string`). Tagging fires a recalc + cache bump via the `IncidentLabel` pivot — NOT the observer; raw pivot writes and deleting/renaming the label record bypass it (remedy: Recalculate button / sweep command).

## Action Improvements lifecycle (PROJ-014, owner 2026-10-14)

- Statuses draft/pending/done/cancelled/tbd; priority optional, default low.
- Reminder emails, escalation and every "overdue" count gate on `status = 'pending'` only — draft, tbd and cancelled rows drop out automatically (tbd was verified free of charge: every gate already selects `status = 'pending'`, no gating code changed).
- Cancelling requires a `cancellation_reason` — enforced in the shared form only (`visible` + `required` when status = cancelled, the `result_note`/done mechanism: hidden Filament fields aren't validated). Deliberately NO observer validation, matching the `result_note` precedent; the write API routes stay disabled so the form is the only write path.
- Evidence: encrypted proof files (jpg/jpeg/png/webp/txt/pdf, ≤15MB) attachable anytime, one key per file (`ActionImprovementEvidenceKey`), ciphertext only on disk (`action-evidence/{uuid}.encrypted`). Evidence is immutable — no edit, delete + re-upload. File events `file_uploaded`/`file_deleted`/`file_downloaded` audit on the parent **Incident** (supporting-doc precedent), not on the evidence row; downloads gate through `ActionImprovementPolicy` view.
- Transition INTO done stamps `completed_at`/`completed_by` in the observer (`??=` — editing a done row never re-stamps); reopening clears both.
- `registerCompletenessPct()` counts action-item EXISTENCE by design — a cancelled item still satisfies register completeness (no governance-number drift).
- The AI Suggest button only ever creates **draft** rows; drafts become real actions by promoting status to Pending.
- **TBD due dates (owner 2026-10-14):** `due_date = null` means TBD — every draft-creation path (AI Suggest, RCA parser/AI extractor, WarRoom retro) stores TBD when no real date is known, never an invented +30d; tables render `TBD`, and TBD rows are never due-soon/overdue/escalated (SQL null semantics, no code added). TBD rows are **tracked, not lost**: `tbd` filter on both tables, warning color on pending TBD, and the widget Pending card shows "N TBD (no due date)" — reminders skip them by design, so these surfaces are the only chase mechanism.

**Status `tbd` ≠ due-date TBD (naming hazard, owner 2026-10-26).** "TBD" already meant *no due date decided* (`due_date = null`); the 5th status `tbd` means something else entirely: *"TBD (to be discussed)"* — no decision yet on **whether/how** to do the action at all. A row can be either, both or neither independently (a `pending` row with a TBD date is chased; a `tbd`-status row with a real date is parked). Status `tbd` is parked exactly like draft/cancelled: gray badge, excluded from every pending gate (reminders, escalation, overdue, widget counts), and surfaces only via the status filter. All code and UI copy must keep the two distinct — constant `STATUS_TBD`, label "TBD (to be discussed)", never reuse the due-date "TBD" wording for the status.

## Cache keys that gate freshness

- `dashboard_cache_version` (bump refreshes dashboard widget cache — every version-keyed cache below must include it in the key payload)
- `analytics_v3_*` (15 min), `mttr_mtbf_trend_v5_*` (15 min)
- `chat_quick_stats_v2` (5 min, cleared by `ChatContextService::clearDataCache()`)
- `mtbf_{tab}_{year}_v{version}` (1 h)
- `incident_corpus_manifest` (no TTL — stale-check state for the incident memory; a `dashboard_cache_version` bump marks it stale)

## Incident Form Validation Rules (2026-09-29 hardening)

Live QA (HTTP-submit matrix) found bad data silently persisting; these constraints are now server-side and must not be removed:

- Fund fields (`potential_fund_loss`, `fund_loss`, `recovered_fund`): `numeric` + `minValue(0)` — negatives are data-entry errors.
- `incident_date`, `entry_date_tech_risk`: `maxDate(now())` — future dates are typos (wrong year) or clock skew.
- `discovered_at`, `stop_bleeding_at`: `afterOrEqual('incident_date')` — the timeline cannot precede the incident.
- Filament API note: `DateTimePicker` has **`maxDate()`**, not `maxValue()` (numeric/text only — using the wrong one throws 500).

## Export-specific rules

See `docs/reference/exports.md` — exclusions, `mttr_formatted` rendering, per-instance MTBF caches, `-`/0 conventions.
