# Sub-Incident Hierarchy — Design

Date: 2026-11-11
Status: approved in conversation (owner answered the clarification rounds and the builder-check follow-up; "go ahead" recorded). Builder check passed 2026-11-11. Next step: implementation plan via superpowers:writing-plans.

## Intent and why

Incidents can cause multiple related impacts, each needing its own investigation and follow-up. Today every impact is an independent incident record with no visible relationship. This project adds a one-level hierarchy:

- **Super-incident** — the main incident.
- **Sub-incident** — a separate, full incident record linked to exactly one super-incident as a related impact.

The hierarchy connects related incidents so users understand the relationship, while each incident keeps its own ID, status, follow-ups, and closure gate. Worked example (owner's): a network incident on 25 Sep 2026 causes two QRIS transaction fund-loss incidents; the network incident is the super, the two fund-loss incidents are its subs.

The central semantic decision, made by the owner: **sub-incidents roll up into the super for incident-count metrics, but keep their own identity for money and for record management.** In the example the dashboard counts **1** incident (the network super), while the fund-loss money of the two QRIS subs still lands on the money surfaces as their own records.

## Decided behavior (owner's calls)

1. **Metrics roll up.** Sub-incidents are excluded from every incident-count metric: Total Incidents, severity breakdowns (P1–P4 + X1–X4), status tabs, KPI counts, Group-By sheets, trends, and the MTTR/MTBF averages and chains. The super is the counted row.
2. **Money stays with subs.** The Fund Loss tile, fund-loss money sums/trends, and root-cause Pareto keep counting sub-incidents as their own fund-loss records. Sub money is NOT summed into the super. (Money follows the actual loss record; the sub usually carries the real root cause.)
3. **Scope: Incidents only.** `IncidentResource` (classification = Incident) gets the feature. `IssueResource` and Issues are untouched.
4. **One level deep.** A sub can never become a super; a super can never be linked as a sub. No recursion.
5. **List: hidden unless expanded.** Collapsed default view shows supers and standalone incidents only; sub rows are not in the pagination totals. A row with subs shows an expand chevron plus a small sub-count badge; rows without subs show nothing. Expanded, the sub rows render nested under the parent (indent + "Sub of" marker), ordered by `incident_date` ascending.
6. **Filtered/searched views surface subs.** If the active filter or search matches a sub, that sub appears as a normal top-level row so it can never be lost; supers remain expandable. Nesting applies to the default (unfiltered) view.
7. **Exports exclude subs entirely.** Sub-incident rows never appear in any of the 5 Excel presets or the markdown ZIP; the super row represents the event. Export baselines stay widget-aligned (widgets exclude subs from counts, so baselines exclude sub rows).
8. **Accepted asymmetry** (owner chose both sides knowingly): dashboard money widgets include sub money; exports exclude subs — so export money sheets contain no sub amounts.
9. **Reporting page and scheduled SendReport mirror the dashboard** (owner call, builder-check round): their row listings and counts exclude subs — the super row represents the event — while their money columns keep counting sub fund-loss. This intentionally replaces the "Reporting/SendReport queries count what they list" precedent (business-rules.md) for subs only; operator-filtered footers on the main working table keep their own precedent.

## Defaults settled in conversation (accepted with "go ahead")

- **One parent per sub.** The link lives in a nullable `parent_incident_id` on `incidents` (no join table). Linking an incident that already has a parent **moves** it — the old link is cleared, nothing duplicated.
- **Deletion:** deleting a super leaves its subs as standalone records (FK `nullOnDelete`, records kept). Unlinking keeps both records (explicit PRD story).
- **Cross-year links allowed** — a 2025 sub may hang under a 2026 super; the existing year-access scope (`applyUserYearAccess`) still decides who sees the sub.
- **"Link existing" picker** offers classification-Incident records only, excluding: the record itself, anything already a sub, and any super (state-based one-level rule).
- **"Create sub-incident"** opens the same blank incident form as normal creation; only the parent link is set on create. Nothing prefilled from the super.
- **Permission:** link / unlink / create-sub / re-link all gated on the existing `update incidents` permission. No new permission.
- **Surfaces:** super's list row has "Create sub-incident" and "Link existing" actions; super's view page gains a "Sub-incidents" section (title, status, severity, fund status, date; open + unlink actions); sub's view page shows a "Sub of" chip linking to the super, with unlink; unlink is also available on an expanded list row. Statuses stay fully independent — no coupling, no auto-closing.
- **Outlier conflict:** if a sub carries the `Outlier` label, the sub-exclusion wins. The Outlier rule ("stays in every count") cannot apply to a sub; its average-exclusion semantics still hold.
- **API:** no new endpoints; `parent_incident_id` may join the existing incident API resource output. Issues out of scope everywhere.
- **Classification guard (my call, builder-check round):** an incident that has a parent or children cannot change classification (Incident ↔ Issue) — the form rejects with an "unlink first" message. A reclassified super would otherwise silently vanish from count metrics together with its excluded subs, and a parented sub would keep a chip pointing outside the Incidents view. Reversible by unlinking. Enforced in both form paths that expose the classification select (`IncidentResource`, `IssueResource`) — this one validation rule is the only touch on IssueResource.
- **Incident Board (my call, builder-check round):** `BoardIncidents` keeps subs as normal flat cards, each with a "Sub of" chip linking to its super; super cards show the sub-count badge. No nesting on the board — drag mechanics stay intact, and hiding subs there would break the never-unfindable rule.
- **Backfill of existing pairs (builder-check round):** none scripted. Prod DB writes are owner-only; the owner links existing real-world pairs (e.g. the 25 Sep 2026 network + two QRIS fund-loss records) by hand through the new UI after deploy.

## Technical approach (my calls — builder may refine within these constraints)

- **Schema:** `parent_incident_id` nullable foreignId on `incidents`, `nullOnDelete`, indexed. Migration; prod run is owner-only per guardrails.
- **Counting exclusion at the single funnel.** `scopeCountEligible()` and `scopeAiCounts()` (`app/Models/Incident.php:169-201`) gain `whereNull('parent_incident_id')`. MTTR/MTBF calculators and every metric query/span compose the same exclusion the way `withoutOutliers()` is composed today (precedent: the Outlier rule). Never patch exclusion per call site — edit-safety rule 3.
- **Money scopes untouched.** The Fund Loss tile scope (`classification Incident + Completed + excludedFromCounts`), `PotentialFundLoss` sums, money trend, and root-cause Pareto keep their current definitions; subs qualify there as themselves.
- **Row-count surfaces.** Operator-filtered table footers on the main table keep their "count what they list" precedent (subs are listed only when a filter surfaces them — decided behavior 6). Reporting and SendReport instead mirror the dashboard (decided behavior 9): listings and counts exclude subs; money columns keep them. The plan must walk `docs/reference/dependency-map.md`'s full surface list and mark each surface in/out per this doc.
- **Cache freshness.** Link, unlink, re-link, create-sub, and delete-super bump `dashboard_cache_version`; `parent_incident_id` joins the IncidentObserver recalculation trigger list (edit-safety rule 2). No dashboard surface may serve stale numbers after a link operation.
- **Validation placement.** Parent-linkability is state-based (no parent AND no children AND not self) and enforced in the link/create form path — the single choke point every link write routes through. No observer throws (repo rule: the incident closure gate is the only throwing observer).
- **Sub IDs are normal** `YYYY_IN_NNNN` ids; no scheme change.

## Constraints (repo rules that bind the builder)

- Laravel 12 / Filament 3.3 / MySQL 8 / Redis cache; queues for heavy work; Form Requests for non-trivial validation; enum queries written from enum cases, never hand-typed strings (edit-safety rule 1).
- Filament table config: declarative (`->contentFooter()` etc. on the resource `table()`) over page-hook methods — page hooks don't run on the `loadTable` render path.
- Never `clone()` a builder that already grouped (edit-safety rule 6). Fresh builder per stat.
- After any relation/column work, grep bare names as **strings** too (`'parent_incident_id'`, `'parent'`), not just arrow syntax (edit-safety rule 5).
- Tests: baseline **1166 tests / 4,583 assertions green (2026-11-10)** must not drop; a drop is a regression, not a refactor. New tests per `docs/reference/testing-standards.md` (host-php invocation, reverb exclusions per that doc).
- Laravel Pint on touched files; no TODO/FIXME in production code.
- `docs/reference/dependency-map.md` updated in the same commit that adds the field (adding a field that feeds dashboard numbers → update the map).

## Goals / Non-goals / Anti-goals

**Goals**
- Record each impact as its own incident while keeping it linked to the main one.
- Create-in-place and link-existing paths; unlink without deleting either record.
- Clear list hierarchy with expand/collapse only where it means something.
- Dashboard numbers reflect the hierarchy the way the owner decided (count roll-up, money stays).

**Non-goals**
- Multi-level nesting; Issues support; new API endpoints; new permissions.
- Status coupling between super and subs (including closure cascades).
- Rolling money into supers; showing hierarchy in exports.
- Changes to the G / Non-Incident rules or the Outlier mechanics themselves.

**Anti-goals (failure even if it works)**
- Double-counting: a sub appearing as a separate counted incident on any count/metric surface.
- Silently dropping sub money from the money surfaces.
- A sub being unfindable: filtering or searching for a sub must always surface it.
- Stale dashboard numbers after link/unlink (cache not bumped).
- Losing records: unlink must never delete; deleting a super must never delete its subs.

## Left to the builder (plan phase decides)

- Exact Filament table mechanics for expand/collapse and nested-row rendering, including state across pagination.
- Which existing scopes/composers the exclusion touches, walked surface-by-surface against `docs/reference/dependency-map.md`, with an in/out mark per surface.
- Migration details, index choices, model relations/casts, form/picker implementation.
- Test placement and coverage per `docs/reference/testing-standards.md`.
- Ordering tie-breaks (subs share an `incident_date`) — minor.
- The implementation plan itself: `superpowers:writing-plans` next.
