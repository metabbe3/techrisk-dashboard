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

### [PROJ-020] Incident list: one Export dropdown

**Status:** Done
**Priority:** P3
**Start Date:** 2026-11-10

#### Description
Owner 2026-11-10: the Incidents list header carried two export buttons ("Export" + "Export Markdown ZIP") — merge them behind ONE dropdown, like the incident detail page's Export group (ViewIncident, 2026-11-04's same header declutter).

#### Tasks
- [x] TDD RED → GREEN: `IncidentMarkdownZipTest::test_both_exports_live_in_one_header_group` — both `export` and `export_markdown_zip` must live inside one ActionGroup labeled "Export" and neither may be a top-level header button (reflection on `getHeaderActions`, the ViewIncident group-test pattern; a dot-path `assertActionVisible('export.export_markdown_zip')` canNOT resolve because `ActionGroup::make()` takes no name). Full suite **1155 tests / 4,560 assertions** (2 known AI env failures + 5 deprecations only).
- [x] `ListIncidents::getHeaderActions()`: the pair wrapped in `Actions\ActionGroup` — label "Export", sky `header-btn-export` styling kept; members relabeled artifact-first ("Excel Report" = the presets form, "Markdown ZIP" = the confirmation) — behavior untouched.
- [x] Docs: exports.md preset-6 line, dependency-map `ExportActionSchema` row, testing-standards baseline bullet.
- [x] Commit / push / PR — merged to main 2026-11-10 (owner ask).

#### Dependencies
- None.

#### Progress Updates
- 2026-11-10: Implemented on `feature/notion-issue-title-date` (same branch as the dated-title + connect-failure work).

### [PROJ-021] Issue sync: dated issue titles

**Status:** Done
**Priority:** P3
**Start Date:** 2026-11-09

#### Description
Owner request 2026-11-09: issues imported from the Notion sync store their title as `[date(yyyy mm dd)] - [title]` — "2026 Nov 09 - timeout to database". The date is the issue's own date (the row's `date_start`, falling back to `createdTime` — the same date `importRow()` already resolves for `incident_date`).

#### Technical Approach
- `NotionIssueMapper::datedTitle()` composes `"Y M d - " . stripDatePrefix(cleanTitle($name))` — the Notion "Summary of Incident - " prefix cleans first and a name already carrying the sync's own format is never doubled. `importRow()`'s create uses it (sync path ONLY — the shared `cleanTitle()` and the CSV importer's stored titles are untouched).
- Dedupe sees through the prefix: `NotionIssueMapper::stripDatePrefix()` (`/^\d{4} [A-Za-z]{3} \d{2} - /`) is stripped from BOTH sides of every title comparison — `titleAlreadyExists()`, the in-run set, and the mapper's `findDuplicateByTitle()`/`existingNormalizedTitles()` — so a re-synced raw Notion name still matches a prefixed stored title (page-ID dedupe via `NotionSyncedPage` stays the primary net; the CSV importer's dedupe inherits the stripping through `findDuplicateByTitle()`).
- Scope discipline: the prefix applies to the sync path only; dry-run `describeRow()` lines keep showing the raw Notion name.

#### Tasks
- [x] TDD RED: mapper unit tests (`datedTitle` composition + dedupe-through-prefix) and the service feature test (`test_imported_titles_carry_the_dated_prefix`, date_start and createdTime fallbacks) — failing for the right reasons — 2026-11-09
- [x] GREEN: mapper `datedTitle()`/`stripDatePrefix()` + comparison stripping; service `titleAlreadyExists()` strip, in-run set strip, create via `datedTitle()` — 2026-11-09
- [x] Existing stored-title asserts reworded to the prefixed format (7 asserts across 3 test files; poison-row `Incident::creating` matchers → `str_ends_with` since the created title now carries the prefix); dedupe tests untouched — they ARE the strip verification — 2026-11-09
- [x] Pint; round chunk 244/1,416 green; **full suite 1150 tests / 4,541 assertions** (2 known AI env failures + 5 deprecations only) — 2026-11-09
- [x] Docs: dependency-map `NotionIssueSyncService` row (dated-title rule), testing-standards baseline bullet, CLAUDE.md baseline — 2026-11-09
- [x] **Live check feedback (2026-11-09, round 2):** (1) the sync's 500 (`Unknown column 'last_edited_at'`) was the edit-sweep migration `2026_11_04_000001` still **Pending on the local DB** (came in with the PROJ-018 merge) — applied locally (additive nullable column; prod migrate stays owner-only); the issue itself had imported fine; (2) **the composed title doubled the date** — the row's own name already carries "9 November 2026 - " (round 7's 195/300 lesson) → `stripDatePrefix()` now strips ANY leading date prefix (the sync's `Y M d - ` + the record's `DD Month YYYY - `/`YYYY-MM-DD - `/`Month D, YYYY - ` shapes, repeated so stacked pairs strip; date-only titles kept; separator required) on BOTH comparison sides, TDD (+3 tests; the service test reproduces the live row verbatim) — full suite **1153 tests / 4,550 assertions** (2 known AI env failures + 5 deprecations only); the two live doubled rows (330, 334) re-composed to the single-prefix format on the local DB
- [x] **Operator feedback round 3 (2026-11-09, sync message):** (1) **the edit sweep's "N refreshed" segment is GONE from the message** (owner: "50 refreshed tidak perlu ditampilkan") — the sweep still runs, logs, and records edit times, it just doesn't report itself; (2) **restores say "Restored"** — "Restored N deleted issue(s)" segment (dry run: "Would restore N deleted issue(s)"), restore-only runs lead with it (no "Imported" lead; "No new issues" only when nothing at all happened); the return's `created` stays the total. TDD (5 message asserts reworded + 2 added) — full suite **1153 tests / 4,552 assertions**. (3) **Why restores are slow — answered, no change:** 2 sequential hosted-MCP round trips per page (the `WHERE url LIKE` lookup + the body fetch, ~1–3s each, no batch endpoint), the picker flow double-looks-up every picked page, and — until the edit-time backfill finishes (was 106 pages left, ≤50/run) — every sync also carries the 50-page × 300ms-paced backfill wave. Nothing to fix unless asked.
- [x] **Connect-timeout round 4 (2026-11-10):** the operator's Connect attempt died UNHANDLED — `ConnectionException`, `cURL error 28: Connection timed out after 2002 milliseconds … https://mcp.notion.com/token` — right after authorizing in Notion. **Root cause in the vendored package, not our config:** `laravel/mcp` v1.1.1's `InteractsWithOAuthEndpoints::oAuthRequest()` hardcodes `->timeout(5)->connectTimeout(2)` on every OAuth request (token exchange AND discovery), and the vendor Connect/Callback route closures call `exchangeCallback()` with no try/catch — a TLS handshake just over 2s (the sync worked minutes earlier from the same container) escapes as a raw 500; v1.1.2 (latest) still doesn't make the timeouts configurable, so no vendor-side knob exists. **Two app-side guards, no vendor coupling:** (1) `AppServiceProvider::boot()` registers ONE scoped Guzzle global middleware raising `connect_timeout` → 10 for every request to the `notion.mcp_url` host only; (2) `bootstrap/app.php` gains a `ConnectionException` renderable beside the existing `OAuthException` one (danger notification "Notion connection failed" + redirect to NotionSettings — click Connect again; the existing renderable's guard widened `mcp/oauth/*` → `mcp/*` so the Connect route renders friendly too). TDD (+2 tests in `McpOauthConnectFailureTest`: the middleware test walks the app's real global stack, the renderer test drives `ExceptionHandler::render`) — full suite **1155 tests / 4,557 assertions** (2 known AI env failures + 5 deprecations only).
- [x] Commit / push / PR — merged to main 2026-11-10 (owner ask; renumbered PROJ-019 → PROJ-021, PROJ-019 was taken by main's DingTalk agent)
- [ ] Next real sync: imported titles read "2026 Nov 09 - Multiple App Error Top Spiking" (single date)

#### Dependencies
- None (rides the existing PROJ-017 sync path)

#### Blockers
- None

#### Progress Updates
- 2026-11-09: Implemented on `feature/notion-issue-title-date`, TDD throughout. RED confirmed (mapper error: `datedTitle` missing; service failures: unprefixed titles), GREEN on the round chunk, full suite 1150/4,541 with only the 2 documented env failures.

---

### [PROJ-018] Start Investigation — a Notion investigation page per incident/issue

**Status:** In Progress (code + tests done; live check + merge pending)
**Priority:** P2
**Start Date:** 2026-11-03

#### Description
Owner request 2026-11-03: "Start Investigation" in the existing row kebab ("…") of the Incidents and Issues tables creates a dedicated investigation **page in Notion** — independent of the Notion issue sync — links it to the record, and moves the status to **In progress** (owner decision: reuse the existing status, no new enum value; PICs get the existing `IncidentStatusChanged` notification via the untouched `IncidentObserver`). The view page shows an "Open in Notion" link, the page body as read-only markdown, and a **Refresh from Notion** header action (manual sync-back only — owner decision). Parent location is **configurable**: a "Investigation pages" section on the Notion Settings page holds the parent page ID, edited through the same Change → Save/Cancel flow as the sync watch ID (owner decision).

#### Technical Approach
- 3 nullable columns on `incidents` (retro-columns precedent): `notion_investigation_page_id` / `notion_investigation_markdown` / `notion_investigation_synced_at`. No unique index; one page per record — `start()` refuses while one is linked.
- `NotionInvestigationService` (`app/Services/Notion/`): `start()` fail-fasts (already linked → parent unset → not connected), then `notion-create-pages` (title `Investigation – {no} – {title}` capped 100, seeded metadata markdown), parses the created page ID from the response text (32-hex regex, parent ID excluded), and ONE atomic update stores the 3 columns + flips status to In progress — status changes only after creation succeeded. `refresh()` re-reads via `notion-fetch`, touching nothing on failure. The parent accepts a page/database URL, raw or dashed UUID, or a `collection://<uuid>` **data source** — data sources route as `parent.data_source_id` and the row's title goes into the database's own title property (read from its schema via one `notion-fetch`; the dashboard databases name it "Name").
- `NotionInvestigationAction` (`app/Filament/Actions/`): single source for both kebab actions (gated `manage issues` / `manage incidents`, hidden once a page is linked), both "Refresh from Notion" header actions, the shared "Notion Investigation Pages" infolist section (renamed round 11), and the toasts (success carries an "Open in Notion" button).
- Zero observer/enum/status-filter changes by design.

#### Tasks
- [x] Migration `2026_11_03_000001` + Incident fillable/casts — 2026-11-03
- [x] `NotionInvestigationService` (TDD, 11 tests) — 2026-11-03
- [x] Row kebab "Start Investigation" on both resources (TDD, 10 tests) — 2026-11-03
- [x] View-page sections + "Refresh from Notion" on both view pages (TDD, 6 tests; ViewIssue got its first `infolist()` — base issue fields reproduced as entries) — 2026-11-03
- [x] Notion Settings "Investigation pages" section (TDD, 6 tests; `NotionWatchIdEditorTest` fi-fo-actions count 1→2 with comment) — 2026-11-03
- [x] Full suite: 1059 tests / 3,953 assertions, 2 failures verified pre-existing (AI persona/corpus tests — identical on a stashed clean tree) — 2026-11-03
- [x] **Live check of the `notion-create-pages` response shape** (2026-11-03): `structured` = null; `text` = JSON `{"pages":[{"id":"<dashed>","url":"https://app.notion.com/p/<undashed>…"}]}` — the URL path carries the bare 32-hex the parser's regex matches; the parent is not echoed. Parser kept as-is (works + shape-drift tolerant). Discovery: the integration bot had edit rights on the "Workspaces" page but NOT on "Summary of Incident 2026" — the parent page must be shared with the Notion connection (grant edit access in Notion or Start Investigation fails with "Bot is missing edit permissions for `parent` page")
- [x] **Data-source parents (operator feedback 2026-11-03):** the operator pasted a `collection://…` data source ID (the Incident Investigation Master database) and hit the 32-hex validation. Fixes: `normalizePageId()` now parses dashed UUIDs; a `collection://…` input is stored verbatim and `start()` routes it as `parent.data_source_id` with the row title in the database's own title property (schema-read via `notion-fetch`, +3 service tests +2 editor tests). Live probe #2: `create-pages` resolves a **DASHED** `data_source_id` only — the undashed form (which `notion-fetch` and `page_id` both accept) fails `object_not_found`; `dashed()` re-dashes before the call. Probe A row created under the Master DB with `Name` as the title property — the data-source flow is live-verified end-to-end
- [x] **Template-following creation + property filling (operator feedback round 3, 2026-11-03):** the Master DB's default new-page template carries the team's heading structure, so database rows are now created **properties-only (no seeded markdown)**; the seeded body remains only for plain-page parents. Probed live: API-created rows are BLANK (the template does not follow automatically) → `notion-update-page` `apply_template` (template id from the schema's `default_page_template`, `allow_async: false`) applies it **asynchronously** (blank at +0s, ready at +2s) → the post-create body fetch retries on blank (3 attempts, 2s apart). Properties: `Incident Date` ← `incident_date` and `Entry Date` ← `entry_date_tech_risk` as `date:{name}:start` = `Y-m-d` (live-verified); `PIC` ← triggering user + portal PICs as an **array of Notion user ids** (live-verified; raw emails are rejected) resolved via `notion-get-users` by exact email match, cached 6h under tag `incidents`; properties absent from the schema are skipped (other databases must not error); a template failure is non-fatal (row still linked + status flips) and the success toast notes it. `created by` CANNOT be filled — Notion-system readOnly (stamps the integration), per the schema's own text; the operator accepted the PIC-property substitute. Also fixed: the stored markdown is now the fetch envelope's `<content>` block, not the whole JSON envelope (the section was rendering JSON soup). Full suite **1066 tests / 4,015 assertions** (only the 2 known env failures)
- [x] Probe rows cleaned up via the live server's `notion-delete-pages` (it exists now — the seeded tool list is outdated)
- [x] **Parent-type detection (operator feedback round 4, 2026-11-03):** the operator's `object_not_found` was a routing bug — `start()` keyed on the stored `collection://` prefix, but the operator had pasted a **bare** data-source ID (then the **database's** own ID from its URL), so the ID went out as `parent.page_id`. Fix: `start()` `notion-fetch`es the parent and detects its type from the response (`<data-source-state>` ⇒ database), never from the prefix; the state's `url` field yields the **true data source ID** (a pasted DB-URL ID is not a valid `data_source_id`), and created-ID parsing now excludes BOTH the parent and the data source (dash-insensitively — the create response echoes the data source first). Full suite **1069 tests / 4,045 assertions**; live E2E from tinker with the real Master DB parent (stored as the database ID): row created, template body fetched, linked, status In progress — probe row trashed after
- [x] **Operator tweaks (round 5, 2026-11-04):** the Settings parent ID is **stored as pasted — dashes kept** (shared `normalizePageId()`; the sync watch ID editor inherits it); the page title is **`<yyyymmdd> - <title>`** off the record's incident date (e.g. "20260925 - QRIS Acquirer"). Full suite **1070 tests / 4,048 assertions**
- [x] **Operator feedback round 6 (2026-11-04):** (1) a small **Notion icon column** on both tables — `IconColumn notion_investigation_link` after the End Date column, arrow-top-right-on-square/info, opens the page URL in a new tab, renders only for linked records; (2) **restart after deletion** — `NotionInvestigationService::unlink()` + an "Unlink investigation" danger header action (confirmation modal: the portal link is removed, the Notion page itself is not deleted) on both view pages so Start Investigation becomes available again; `refresh()` now also detects the **deleted-page envelope** (`<page … deleted>` + `<blank-page>`, fetches WITHOUT error — live-verified on the operator's BRI page) and points to Unlink; the stuck record 20261030_IS_4950 was unlinked live; (3) the section's plain "Open in Notion" text entry became a real **footer button** (`Section::footerActions()`, owner: too easy to miss); (4) full-body storage verified against the operator's real 59 KB working page — `<content>` captures the entire body, nothing truncated. Full suite **1077 tests / 4,094 assertions**
- [x] **Operator feedback round 7 (2026-11-04):** (1) the page title duplicated the date ("20261031 - 31 October 2026 - …") — **195/300 record titles carry their own "DD Month YYYY - " prefix**; `withoutDatePrefix()` strips it before composing (separator required, full title kept if nothing remains); (2) **the section showed only "Table of Content"** — root cause found: Filament's `Str::sanitizeHtml` (Symfony HtmlSanitizer, runs on every markdown entry) drops the Notion envelope tags **with their content**; new `NotionBody::toHtml()` translates toggles → headings + children, drops the TOC widget, unwraps meeting-notes/unknown/date — applied at render time so already-stored bodies heal without a re-fetch (live-verified: 30 sanitizer chars → 842 with all headings + tables); (3) the header simplified: Post-Mortem PDF + Export to Markdown merged behind an **Export** dropdown, Refresh from Notion + Unlink investigation behind a **kebab** (both view pages; owner left kebab-vs-dropdown to us). Full suite **1084 tests / 4,130 assertions**
- [x] **Operator feedback round 8 (2026-11-04):** (1) Refresh + Unlink moved **out of the header kebab into the section footer next to "Open in Notion"** (infolist footer actions; the section carries an explicit `->key('notion_investigation')` so tests can `callInfolistAction`); (2) **every Notion heading toggles in the portal** — `NotionBody` now renders toggles as `<details class="cursor-pointer select-none" open><summary>…</summary>` (expanded by default so nothing looks missing; `open` + `class` survive the sanitizer — live-probed); (3) **Notion AI-block content now imports**: the page envelope renders AI blocks only as self-closing `<unknown url="…#<block id>" alt="ai_block" />` placeholders — fetching the anchor id as a page returns the generated content (live-verified: the generated Invitation Email block returned its full text; empty blocks return `<blank-page>`); `inlineAiBlocks()` (shared by `refresh()` and the create path's body fetch) replaces each placeholder with the block's fetched body, drops empty blocks, and keeps the placeholder on a failed block fetch so the next refresh retries; (4) **opening a view page auto-refreshes a stale copy** — `NotionInvestigationAction::autoRefresh()` on both view pages' `mount()` (parent::mount first), quiet (no toasts), skips copies synced within 60s and anything without a linked page, and the page permission-gates it (`manage incidents`/`manage issues`) so a view-only visitor never triggers the write. Env note: the suite must run on HOST php — inside the app container `localhost:8081` cannot reach reverb and broadcast tests error. Full suite **1091 tests / 4,159 assertions** (2 known AI env failures excluded)
- [ ] Manual E2E (kebab → Start Investigation → Open in Notion → edit → Refresh from Notion); note: the Notion row's `Status` stays at the DB default "Initial" (portal flips to "In progress" — operator chose not to fill the Status property; the DB's "Investigating" option would be the natural later mapping)
- [x] **Operator feedback round 10 (2026-11-04):** (1) **"table content not fully imported"** — root cause: `inlineAiBlocks()`'s `?? ''` replaced an UN-GENERATED ai_block's fetched `<blank-page>` envelope (no `<content>`) with an empty string, deleting the placeholder — and with it the whole RCA/MOM/Invitation section — from every stored body; the placeholder is now KEPT and renders as a muted note ("AI block — not generated in Notion yet. Run it there, then Refresh."); a live probe also proved `apply_template` copies ai_blocks AND file attachments (blank at t+0s, complete at t+2s), and `<file source="notion-file-block://…&name=x.md" />` attachments render as named notes (the scheme is MCP-internal — unlinkable from the portal; the name is a query param inside the source URL); (2) **unlink no longer hides the section** — `unlink()` on an alive page is SOFT: page id kept, `notion_investigation_unlinked_at` set (5th column, amended into the still-uncommitted migration), the section shows an "Unlinked from Notion" note and the refresh button relabels **"Relink investigation"**; `refresh()` of a soft-unlinked record IS the relink; `start()` on one CONTINUES the existing page (no duplicate; status not clobbered) and only creates a FRESH page when the old one proves deleted; `autoRefresh()` never silently relinks; (3) **heading indentation** — lower-hierarchy summaries indent right (h2 `pl-4`, h3 `pl-8`, h4–h6 `pl-12`, h1 flush); (4) **"Synced at Y-m-d H:i"** on one line (hiddenLabel + formatState). Prod migrate pending owner — now **5 columns**.
- [x] **Operator feedback round 14 (2026-11-05):** (1) **the marker vertically centered against the heading TEXT** ("misal jika tinggi font 11 maka posisi toggle di 6", then "should be vertically centered ngono lah" on the first cut) — iterated three times: the final shape is a `flex! cursor-pointer` summary with the CSS-border triangle span (`mr-1.5 inline-block h-0! w-0! border-y-4! border-y-transparent! border-l-[6px]! align-middle`, no SVG/font needed) living INSIDE the heading tag — `vertical-align: middle` centers it against the text itself so it holds at every heading size; the FIRST cut (span flex-centered in the summary) centered on the line box incl. descender space and rode visibly low on big headings, and moving the span inside while dropping `flex!` let Filament's `summary{display:list-item}` back in — browsers painted their NATIVE gray marker above the heading (double marker, caught by re-screenshot; `flex!` restored). (2) **the marker is colored, light AND dark safe** ("selain hitam atau putih") — `border-l-indigo-500!`, the panel's own primary (`Color::Indigo`), rotating down when open via `transition-transform group-open:rotate-90` (the variant keys off `class="group"` on the `<details>`). **Root cause of "still not fit" after round 13: the panel had NO CSS for any NotionBody utility** — `AdminPanelProvider` has no `viteTheme`, a render hook injects the app's Vite build, and `public/build` was from Oct 23 (pre-PROJ-018), so every utility since round 10 (`pl-4/8/12`, `my-5`, `inline`, `space-y-1`…) had no stylesheet behind it; after `npm install` (missing `highlight.js/lib/common`) + rebuild, Tailwind 4's auto content detection covers `app/**/*.php` (no `@source` needed) and all classes compile. **Second root cause (owner STILL saw no marker after the rebuild, found by screenshotting the real /admin/incidents/44 in headless Chrome): the app build is Tailwind 4 @layer-ed but Filament's own `css/filament/filament/app.css` is UNLAYERED Tailwind 3 preflight — unlayered styles beat layered ones at any specificity — so its `summary{display:list-item}` killed `display:flex` and its universal `*{border-width:0;border-color:…}` zeroed the triangle's borders; the marker rendered as nothing.** Fix: Tailwind v4 trailing-`!` on the colliding classes (`flex!` + `h-0! w-0! border-y-4! border-y-transparent! border-l-[6px]! border-l-indigo-500!`, TOC `list-none! p-0!` vs the prose list rules); re-verified visually on record 44 (final shape: ONE indigo marker per section, centered against the heading text, no native marker, TOC bullets gone). Lessons: **changing NotionBody's classes requires `npm run build`, any utility colliding with Filament's preflight needs `!`, and `flex!` on the summary is itself load-bearing (kills the native `<summary>` marker).** Full suite **1117 tests / 4,310 assertions** (2 known AI env failures excluded)
- [x] **Operator feedback round 13 (2026-11-04):** (1) **Incident Category** — the database row's select property filled with the portal severity (verbatim P1–P4/X1–X4; G / Non Incident → the DB's "N" option; a severity with no option in the DB — live options are N, P0–P4, X3 — is skipped with a note instead of failing the create). (2) **Entry Date** — filled from the portal record's **created date** (was `entry_date_tech_risk`). (3) **Incident Date** — verified it comes from the portal's **Occurred Time** (that label IS `incident_date`); pinned by test. (4) **Fund Loss** — Yes/No select from the portal's fund-loss linkage: Yes when `fund_status` is set and not `NonFundLoss`, else No. Select wire format live-probed with one throwaway page (created → fetched → deleted): plain option-name strings. (5) **body content selectable** — `select-none` dropped. (6) **the disclosure marker on the same line as the title** — the summary's heading renders `inline`. Full suite **1117 tests / 4,310 assertions** (2 known AI env failures excluded)
- [x] **Operator feedback round 12 (2026-11-04):** (1) **the TOC is clickable** — every toggle gets a positional anchor `notion-sec-N` (document order; the pre-substitution callback must capture the counter by reference — an arrow fn captured it by value and every section got the same id), the `<details>` carries the id and the TOC entry a fragment link; bare `#` headings stay plain; verified live on records 44/313 (8/10 entries each pointing at its own section). (2) **summary typography matches the portal prose** ("seperti pada Root Cause Analysis") — summaries carry real heading tags (`<summary class="cursor-pointer"><h2 class="inline pl-4">Title</h2></summary>`) styled by the infolist's Tailwind-prose classes; the round-9 custom typography and the round-11 child wrapper are removed (prose covers both). (3)+(4) **Notion settings labels**: section "Notion investigation pages", field "Investigation page ID" (guard: the old "Investigation parent page ID" must not appear). Full suite **1113 tests / 4,268 assertions** (2 known AI env failures excluded; 5 pre-existing doc-comment-metadata deprecations enumerated — none in round-12 files)
- [x] **Operator feedback round 11 (2026-11-04):** (1) **body spacing/line-height** — section `<details>` separation `my-3` → `my-5` and the children wrapped in `<div class="leading-relaxed space-y-2">` (the div opens ADJACENT to the summary; children after blank lines still convert — the wrapper is removed in round 12, prose styles cover it); (2) **the table of contents is REBUILT, not dropped** — Notion's TOC is tool-generated (like an AI block) and the envelope carries only the self-closing tag, so `NotionBody::collectHeadings()` takes the page's headings off the raw markdown (toggle titles + bare `#` headings, one combined scan = document order; the TOC's own heading and tag-looking first lines excluded) and writes a plain indented list where the tag stood (h1 flush, h2 `pl-4`, h3 `pl-8`, h4+ `pl-12`; no headings → nothing written per the owner's fallback); verified live on stored records 44/313 (8 and 10 entries, nested Generator/Revised indented under MOM); (3) **the section is renamed "Notion Investigation Pages"**. Full suite **1112 tests / 4,262 assertions** (2 known AI env failures excluded)
- [x] **Operator feedback round 9 (2026-11-04):** (1) the ViewIncident header carried **two Export dropdowns** — a round-8 edit had duplicated the ActionGroup; deduplicated + a count assertion; (2) **summary typography follows the heading level** (`NotionBody`: h1 `text-2xl font-bold`, h2 `text-xl font-semibold`, h3 `text-lg font-semibold`, h4–h6 `font-semibold`; details carry `my-3` — owner: "memperhatikan heading 1, 2, or 3"); (3) **Re-investigate for deleted pages**: a new `notion_investigation_deleted_at` flag column (amended into the still-uncommitted migration) is set when `refresh()` meets the deleted envelope (including via the auto-refresh on open, so the flag renders in the same request) and cleared on a successful refresh/unlink; a flagged incident's section footer swaps Open/Refresh/Unlink for a single **Re-investigate** button (flagged ISSUES keep Unlink — start is incident-only). **Restore-from-trash is impossible on the MCP server** (live-probed: `notion-update-page` commands are exactly update_properties/update_content/replace_content/insert_content/apply_template/update_verification; `notion-move-pages` leaves the page trashed) so Re-investigate = unlink + a FRESH page (the deleted one stays in Notion's trash); (4) **Start Investigation is incident-only** — service classification guard before any Notion call, and the Issues kebab action removed (legacy-linked issues keep the icon column + view section). Every service-test record now pins classification — `IncidentFactory` randomizes it by default. Full suite **1099 tests / 4,187 assertions** (2 known AI env failures excluded)
- [ ] Merge + deploy — **prod migrate pending owner** (owner-only guardrail: `php artisan migrate --force` adds 4 nullable columns to `incidents` on production)

#### Dependencies
- Notion MCP connection (PROJ-017); parent page ID set on the Notion Settings page.

#### Blockers
- None

#### Progress Updates
- 2026-11-03: Implemented on `feature/notion-start-investigation`, TDD throughout. Owner decisions settled up front: configurable parent page; reuse `In progress`; manual refresh (no scheduled sync); row kebab menu.

---

### [PROJ-019] DingTalk stream-mode AI agent (Go sidecar)

**Status:** In Progress (dev smoke test)
**Priority:** P2
**Start Date:** 2026-11-10

#### Description
Interactive Q&A bot in DingTalk answering from dashboard data: @mention in a group or DM 1:1, the agent (fork of metabbe3/GoAgent, MIT) calls three read-only Laravel v1 API tools (`search_incidents`, `get_incident`, `get_incident_markdown`) and streams the reply into a DingTalk AI card (typewriter), falling back to a one-shot webhook reply. Stream mode = outbound WebSocket — works behind the VPN-only prod network, no public IP. Also ships a proactive DM push path (`oToMessages/batchSend`) tested via `./agent -dm <staffId>`, earmarked as an extra delivery channel for the existing `reminders:send-*` commands (hook at `ReminderMail::send()`, one rule one surface).

#### Technical Approach
- Zero Laravel code changes — data API only (`.env.example` keys + compose service are the only root-repo changes).
- `dingtalk-agent/` Go module: official `dingtalk-stream-sdk-go` v0.9.2-beta.1 transport; upstream commit `b71ae8ee` surgery log in `dingtalk-agent/README.md`.
- LLM via dashboard's AI gateway env (`AI_API_*` → `LLM_*`), default model `glm-5.3-flashx` (owner 2026-11-10).
- Open access by default: empty allowlist = everyone in the app visibility range (owner directive 2026-11-10); per-conversation history (12 msgs, 12h TTL); msgId dedup; guardrails on every streamed frame.

#### Tasks
- [x] Fork surgery + 3 dashboard tools + stream handler + tests — Done
- [x] AI-card streaming reply + webhook fallback — Done
- [x] DM push path + `-dm` CLI — Done
- [x] Dockerfile + compose service + `.env.example` + README — Done
- [x] Document ingestion (send file → summarize → knowledge memory → delete file) + `start.sh` — Done
- [x] v2 capability upgrade (corrected domain prompt, 60-iter, max_tokens, web research, 8k replies, receipt acks, message batching) — Done 2026-11-10
- [x] Per-user auto-learning (profiles.json, `AGENT_AUTO_LEARN`) — Done 2026-11-10
- [x] Hard guardrails (input gate + constitution tail + output net, no kill switch) + PDF/deck deliverable tools with DM delivery — Done 2026-11-10
- [x] Read-only Notion doc tools (search/query-database/read-page/add-database via dashboard `/notion` proxy, persistent catalog, knowledge-append, 48k read cap) — Done 2026-11-10
- [ ] Live smoke test (dev app creds; owner DMs bot, staffId captured) — In Progress
- [ ] Owner: mint API token, grant `qyapi_robot_sendmsg` + card perms — Pending
- [ ] (Follow-up scope) DingTalk channel on `reminders:send-*`; auto-create skills — Backlog

#### Blockers
- Dev DingTalk credentials are temporary — owner rotates the secret after testing.

#### Progress Updates
- 2026-11-10: Built, `go vet`/`go test` green (hermetic); bot live-logged in with dev creds; awaiting owner first message to capture staffId.
- 2026-11-10 (later): Live smoke verified — stream connect, allowlist inversion (empty = everyone, gated by app visibility; owner directive), staffId capture from deny logs, proactive DM via `oToMessages/batchSend` (2xx), interactive reply via webhook fallback (AI card blocked on `Card.Instance.Write` grant). Upgraded SDK v0.9.1 → v0.9.2-beta.1 (fixed `send on closed channel` panic killing the process on connection loss). Added persistent memory (`DINGTALK_DATA_DIR`: users.jsonl + history.json, auto save/recall across restarts). Data answers pending real `DASHBOARD_API_TOKEN`; reminder-DM Laravel channel still backlog.
- 2026-11-10 (latest): Document ingestion shipped — file msg → `POST /v1.0/robot/messageFiles/download` (contract pinned live) → extract (txt/md/csv/json/log/xml/docx/pdf via `ledongthuc/pdf`) → summarize per sender caption → summary appended to `knowledge.jsonl` (last 8 injected into every system prompt) → downloaded file deleted on completion (deferred remove, success or failure; only summaries persist, never files). `./start.sh` = easy dev start (loads `dingtalk-agent.env`). All Go tests green. Awaiting owner file-send test; picture/audio msgtypes unsupported in v1.
- 2026-11-10 (v2 upgrade): Prod real usage exposed wrong domain teaching (X1–X4 were mislabeled "near-miss" — truth: non-tech incidents; G = glitch; nothing taught `incident_source`/`loss_taken_by`/Outlier/fund statuses/Issue cross-check; `meta.total` is a RAW count). Shipped: prompt rewrite with verified severity truth + official-count subtraction recipe; `LLM_MAX_TOKENS` (falls back to `AI_WAR_ROOM_MAX_OUTPUT_TOKENS`, default 16384, `0` omits); `AGENT_MAX_ITER` default 60 (Hermes parity, owner directive); keyless web research (`web_search` DDG HTML + `web_fetch`, SSRF-guarded dialer, `AGENT_WEB_TOOLS` kill switch); reply cap 3800→8000 (`AGENT_MAX_REPLY_CHARS`); instant receipt acks ("On it…" on pickup, only when AI card unavailable since the card itself signals processing); per-conversation message batching (rapid messages debounce-join into one LLM turn, messages during a run queue as follow-up); "more" continuation rule in prompt. `go vet` + full Go suite green. Prod redeploy pending (`docker compose up -d --build dingtalk-agent`, config log must show `max_iter=60 max_tokens=16384 web=true`). Scrapling noted as future sidecar upgrade path for harder scraping (Python; current stdlib parse covers DDG HTML).
- 2026-11-10 (auto-learning): Per-user preference memory shipped — after each completed turn a background no-tools LLM call extracts "how this person likes replies" (language, format, standing instructions) from the last 8 messages into `data/profiles.json` (keyed by staffId; ≤8 lines × 150 chars; empty extraction never wipes; failures logged + skipped, never break chatting). Lines are injected into that sender's system prompt only (`Loop.Run/RunStreaming` gained a per-run `sysExtra`; global `SystemExtras` unchanged). `AGENT_AUTO_LEARN` kill switch (default on). Conversation/file/user memory verified by tests + live checks (`cat /data/{users.jsonl,history.json,knowledge.jsonl,profiles.json}` on prod).
- 2026-11-10 (guardrails + deliverables): Hard Go-side guardrails shipped, owner directive, **no kill switch by design** — 3 layers: (1) `guardrail.InputGate` refuses prompt-injection (EN+ID: ignore/forget…instructions, reveal prompt, "you are now a…", jailbreak/DAN, fake `system:` prefixes) and off-topic asks (poem/puisi/cerita/story/essay/song/resep + codegen verb+object pairs) with a FIXED message before any LLM call (file captions gated too); (2) every user msg wrapped in data markers (`FrameUserInput`) + `ConstitutionTail` system message riding as the LAST message of every LLM call (never persisted/pruned); (3) `HardGuardReply` output net at `FilterReplyCap` + card-writer override latch: identity renames/renouncements (unless "Risk Agent"), verbatim prompt-structure leaks, off-topic delivery, language-tagged code fences → fixed refusal. False-positive suite pins legal phrases ("write a summary of incident X", "ignore completed incidents in the count", "show me the rules for MTTR", plain/sql fences). Plus deliverable capabilities: `generate_pdf_report` (go-pdf/fpdf, markdown→A4 PDF) and `generate_deck` (stdlib-zip native PPTX, ≤20 slides) writing `<DataDir>/exports/`, tool result embeds `[generated-file: …]` ticket; post-turn `deliverGenerated` uploads via `POST /v1.0/robot/messageFiles/upload` (contract needs live pin) + DMs via batchSend `sampleFile`; traversal-refusing path resolution; failure never fails the turn. Full Go suite green (8 pkgs). Prod rebuild pending; live checks: injection→refusal w/o LLM log, off-topic→refusal, identity hold, PDF+deck arrive in DM, normal questions unaffected.
- 2026-11-10 (Notion docs, Go half): Read-only Notion tools shipped — `notion_search`, `notion_query_database`, `notion_read_page` (per-tool result cap 48,000 via new `RegisterCapped`/`ResultCap`; all other tools keep the 8k default), `notion_add_database`, all proxying the Laravel `/notion` proxy endpoints with FLAT schemas (`TestAllToolSchemasFlat` now covers 11 defs — nested schema types 400 the whole upstream request, the prod outage that morning). Persistent catalog `data/notion_catalog.json` (upsert-only: `NOTION_DATABASES` env seeds + `notion_add_database` never delete; unknown name-like refs error with the catalog listing so the LLM self-corrects without a server call; query tool description rebuilt from the live catalog). Notion client gets its own 90s HTTP client (MCP is slow; shared dashboard client stays 30s). After each turn `learnNotion` drains `TakeNotionReads()` and a background no-tools LLM call appends ≤6 per-page summary lines to `knowledge.jsonl` as `Notion: <first title>` — flows into the existing last-8 knowledge prompt. Prompts: `NotionPrompt` block (read-only, one page at a time, 100-row paging recipe), DomainRules "Technical documents (Notion)" subsection, ConstitutionTail scope + deliverables bullets expanded; guardrail refusal strings now mention Notion docs (regexes untouched, test-pinned). `AGENT_NOTION_TOOLS` kill switch (default on). Full Go suite green (8 pkgs); Laravel proxy half ships separately (token needs the `notion` endpoint ability ticked, or empty allowlist).
- 2026-11-11 (notion 403 diagnosis + fix): Prod `notion_*` calls 403'd while incident tools worked — root cause is the prod `dingtalk-agent` API token's `allowed_endpoints` (pre-PROJ-019 list, no `notion`); `CheckApiTokenAccess` rejects `api/v1/notion/*` before the controller runs, so the "invite the integration in Notion" theory the LLM replied with was wrong (NotionController only 503s/502s; Notion was never reached). Go-side fix: the dashboard client's 403 handler now passes the server's message through (`dashboard api forbidden (HTTP 403): <server msg> — tick the missing endpoint ability…`) instead of a hardcoded generic line that hid the cause; test-pinned in `TestDashboardErrorSurfacing` (asserts the server's "boom" body survives on 403). Full Go suite green (8 pkgs). **Owner action to unblock prod:** Filament → API Tokens → `dingtalk-agent` → tick `Notion (read-only docs)` in allowed endpoints (or clear the allowlist), then redeploy the agent (`docker compose up -d --build dingtalk-agent`) to pick up the clearer error text.

---

### [PROJ-017] Notion MCP connection, AI-chat tools, scheduled sub-page sync


**Status:** Done (live-verified 2026-10-27; merge pending)
**Priority:** P2
**Start Date:** 2026-10-23

#### Description
Connect the dashboard to Notion via MCP (Model Context Protocol) against Notion's hosted MCP server (`https://mcp.notion.com/mcp`, streamable HTTP, OAuth 2.1 + PKCE with dynamic client registration — no manual Notion app or API token). Three capabilities share the one workspace-wide connection: (1) four AI-chat tools (search/fetch/create-pages/update-page) exposed through the existing `AgentTool` registry — chat-only by construction since War Room requires explicit `enabled_tools` opt-in and scheduled agents are text-only; (2) a scheduler (09:00/12:00/15:00/18:00 WIB — owner-set cadence 2026-10-27, was every 15 min) that fetches a configured watch page/database and auto-imports new sub-pages into the **Issue list** (`classification='Issue'`), deduped on Notion page ID (not title) via the `notion_synced_pages` table, mapping fields through `NotionIssueMapper` — logic extracted from `IssuesImporter` so the CSV importer and MCP sync cannot drift (BUG-008 lesson); (3) a Notion Settings admin page (connect/disconnect, watch target, last-sync status) gated on `manage api tokens`. Tokens live encrypted (`Crypt::encryptString`) in `Setting` key `notion_mcp_token`; refresh is lock-guarded with re-read-after-acquire because Notion rotates refresh tokens on use. Supersedes the discarded direct-REST Notion integration.

#### Technical Approach
- `laravel/mcp` v1.1+ client (`Client::web()` + `withOAuth()` + lazy `withToken()`), registered in `AppServiceProvider`, OAuth routes via `Mcp::oAuthRoutesFor('notion', …)` — **APP_URL must be publicly reachable** for the callback.
- `app/Services/Notion/` — `NotionMcpClient` (connection/refresh/callTool, never throws toward chat), `NotionIssueMapper` (shared mapping), `NotionIssueSyncService` (sync + dedupe), `Tools/*` (4 `AgentToolInterface` passthroughs).
- `notion-fetch` on a database returns its **schema** (incl. the queryable `collection://<uuid>` table), not rows — the sync therefore goes fetch → `notion-query-data-sources` (arguments wrapped in a `data` object) → flat rows. Verified live 2026-10-27.
- **Schema assumption (owner decision 2026-10-27: keep as is):** the sync is hard-coded to the current database template's property names (`Name`, `Date of Incident`, `Classification by Tech Risk Incident`, `Root Cause Classification`) and one watch target at a time. A database with the same template = zero code changes (swap the watch target); a different schema fails loudly with the MCP "no such column" error. Auto-detection of properties was offered and declined — reconsider only if a differently-shaped database needs syncing.
- Detailed design: `docs/superpowers/specs/2026-10-23-notion-mcp-design.md`

#### Tasks
- [x] Install `laravel/mcp:^1.1` (v1.1.1; framework upgraded 12.45.1 → 12.70.1), `config/notion.php`, client registration — 2026-10-23
- [x] `NotionMcpClient` (token store/refresh/disconnect, `callTool`/`callToolDetailed`) + OAuth routes — 2026-10-23
- [x] 4 chat tool classes + seeding migration + registry-cache clear — 2026-10-23
- [x] `NotionIssueMapper` extracted from `IssuesImporter` (importer now delegates to it) — 2026-10-23
- [x] `NotionIssueSyncService` + `notion_synced_pages` table + `notion:sync-issues --dry-run` + 15-min schedule — 2026-10-23
- [x] Notion Settings Filament page (connect/disconnect/sync-now/watch target) — 2026-10-23
- [x] Tests: 46 Notion tests + 15 importer tests green; Pint clean — 2026-10-23
- [x] Live verification: OAuth connect on localhost, sync flow reworked to the real `notion-fetch`/`notion-query-data-sources` shapes (commit `6390f56`), real sync imported 100 Issues, re-run 0 (dedupe proven), chat tool round-trip OK — 2026-10-27
- [x] Migrations ran on the real (local) MySQL DB — 2026-10-27
- [x] OAuth connect/callback routes gated to `manage api tokens` (was any logged-in user — commit `1733600`); incremental sync via `createdTime` watermark so each run reads only new rows, not the full window (owner request 2026-10-27, live-verified: backfill run then `WHERE createdTime >=` accepted by the server)
- [x] Sync button on the Issue list ("Sync with Notion" between Import and New Issue, gated `manage api tokens`, shared `NotionSyncAction` — commit `fa6b6f7`); schedule 09:00/12:00/15:00/18:00 WIB (commit `5a3a531`); boundary re-read no longer counted + held-back segment only when > 0 (commits `450d6e3`, `4d40897`)
- [x] Restore semantics (owner 2026-10-28): sync = reconcile — deleting a Notion-synced Issue is temporary, the next sync re-imports it; permanent removal = delete in Notion. `notion_synced_pages.incident_id` → nullable `nullOnDelete` (migration `2026_10_28_000001`), dangling "tombstone" rows re-imported at sync start via direct per-page lookup `WHERE url LIKE '%<page_id>%'` (verified live; a watermark-rewind design was replaced the same day — the server's ~100-row page cap made rewinds re-read up to 100 already-synced rows per run and legacy rows forced multi-run full backfills). Owner's two 27-Oct HBASE rows (cascade-wiped under the old FK) recovered by a one-time watermark clear + backfill walk
- [x] Full-sync restore picker (owner 2026-10-30): the "Sync now" modal's Full-sync checkbox now reveals a checkbox list (Select All included) of the deleted issues a full sync WOULD restore (`NotionIssueSyncService::restorableIssues()` — live/titled/non-duplicate tombstones only, the same filter the run applies); the operator restores only the ticked pages — unticked issues stay deleted. `[]` pick = deliberate restore-nothing (new rows still import); no pick (console/scheduler) = restore all. The Preview modal's "Continue full sync" swaps the preview for the picker with full sync pre-ticked — it never syncs by itself; the pick, confirmed on the picker, is what runs the restore
- [x] Two-step full-sync flow + dry run removed from the UI (owner 2026-10-30, redesign): "Sync now" opens step 1 — the full-sync checkbox with a submit labelled "Sync", which reads "Full sync" once ticked; clicking it (ticked) reads Notion and the same modal becomes the restore picker (Select All included), where the submit is "Import" — greyed out until at least one issue is selected (the UI can no longer produce the `[]` restore-nothing pick; the service keeps that semantics for the console). Cancel and the X close both steps, and a closed modal re-opens at step 1. The dry-run Preview button, its modal, and `filament.actions.notion-dry-run` are GONE — the picker list IS the preview; `notion:sync-issues --dry-run` (console) keeps the service-level dry run
- [ ] Merge `feature/notion-mcp` + deploy (production migrate + Notion connect there)

#### Dependencies
- None (verified locally end-to-end)

#### Blockers
- None

#### Progress Updates
- 2026-10-23: Phases A–D implemented on `feature/notion-mcp`, tests written and passing. Full suite: 907 tests, 7 failures verified pre-existing (6 × Reverb BroadcastException with Docker down + 1 × ChatPersonaParityTest — identical on a clean tree via stash). Flagged pre-existing gap: `IssuesImporter` production path leaves NOT NULL `incident_type`/`incident_source` unset (strict-mode inserts would fail; the sync service sets them explicitly).
- 2026-10-27: Live-verified on local Docker. Discovery: `notion-fetch` returns a database's schema, not rows → sync switched to the `notion-query-data-sources` flow (commit `6390f56`); severity now reads the database's own "Classification by Tech Risk Incident" column first; title-based dedupe added so CSV-imported rows are not re-created; sync failures surface MCP error text + raw-response preview in the notification; chat-tool parameter schemas corrected against the live server. Result: 100 Issues imported (7 P1 / 3 P2 / 5 P3 / 4 P4 / 93 G), second run 0. Owner decision: sync stays bound to the current database template — swapping databases with the same template needs no code.

---

### [PROJ-016] Add Action Items button on RCA documents

**Status:** In Progress (iteration 3: AI rewording of drafts)
**Priority:** P2
**Start Date:** 2026-10-14

#### Description
Owner request 2026-10-14: a button on each uploaded RCA document (Supporting Documents tab) that quick-adds the document's action items to the incident's Action Improvements, "can later be edited one by one". Owner explicitly rejected AI extraction ("do we need AI? if already have MD?") — the RCA template always carries an `## ACTION IMPROVEMENT` section, so the parse is **deterministic over the converted markdown**: synchronous row action, no job, no notification, zero tokens. `DocumentActionItemService::extractFromMarkdown()` survives the heavily mangled PDF→MD output (inline ``` fences, mid-line `## DONE`, owner names on their own lines — the owner's Dana Cicil RCA is the test fixture); items become **draft** rows (due +30d, PIC empty, priority low, provenance note naming the file), deduped case-insensitively incident-wide (re-click = 0 new). Draft creation lives in `DocumentActionItemService::createDrafts()`, extracted from `Ai\ActionSuggestionService` — one loop, two consumers (AI + document paths cannot drift). Deliberate simplification: owners/due dates in the section are NOT parsed (too mangled to trust); everything editable per row. Button visible only when conversion completed + `manage incidents`; toasts: N added / Nothing new / No section found.

#### Tasks
- [x] `DocumentActionItemService` (parser + shared `createDrafts`) — 2026-10-14
- [x] `ActionSuggestionService::generate()` refactored onto the shared loop (behavior-preserving; PROJ-014 tests unchanged) — 2026-10-14
- [x] `add_action_items` row action on `InvestigationDocumentsRelationManager` — 2026-10-14
- [x] 7 tests (`DocumentActionItemsTest`); pint; full suite 847/2,875 (1 known pre-existing RunAiAgentJobTest failure) — 2026-10-14
- [x] Docs (CLAUDE.md dependency-map rows + baseline; this entry) — 2026-10-14

#### Iteration 2 — AI extractor (owner 2026-10-14: "i think can select AI? so it know which action improvement not auto cut we can select all available model" + "you make the prompt so AI understand which to find and add and column in action improvement" + "like if PIC have multiple name, if user exist can auto to that user if not exist can be just name later can be edited")

The deterministic parser's trimming mangled some items. The button now opens an **Extractor** picker — Fast parse (no AI) + every available model (default = AI default model; AI options gated on `isAvailable()`, so a config-without-gateway env keeps fast-parse-only). The AI path (`DocumentActionItemService::extractWithAi`, prompt `ai.prompts.document_action_items`) reads the whole document markdown and maps the register columns: title (≤100 chars, verb-first), detail (+ verification standard + Owner suffix), priority, due_date (strict Y-m-d + checkdate, else +30d — never invented), and owners — each owner resolved to an existing user by exact full name (team tag stripped): matched → auto-assigned as PIC (fires the existing assignment email, directory PICs included), unmatched → the name stays in the detail for later editing. DONE-marked items included (register counts existence). Failure/no-items share one honest warning naming both possibilities — no silent parser fallback.

- [x] Prompt `ai.prompts.document_action_items` (config/ai.php) — 2026-10-14
- [x] `extractWithAi()` + owner-name→user resolution + per-item due-date validation in `DocumentActionItemService` — 2026-10-14
- [x] Extractor Select + parser/AI branch on the `add_action_items` action — 2026-10-14
- [x] 4 tests (AI path with model+due_date+owner match, invalid date/priority fallback, empty result, explicit parser branch); pint; full suite 851/2,917 (1 known pre-existing RunAiAgentJobTest failure) — 2026-10-14
- [x] Docs (CLAUDE.md row rewrite + baseline; this entry) — 2026-10-14

#### Iteration 3 — AI rewording of drafts (owner 2026-10-14: "maybe draft action improvement can also di rewording ? make it more professional and detail ?")

Owner chose BOTH (via question): a per-row **Reword (AI)** button on drafts AND professional wording at extraction time. The shared row action (`ActionImprovementFormSchema::rewordAction()` — one builder wired into the relation manager and the global page) shows on **draft** rows only (AI available + `manage incidents`), offers the model picker (default = AI default model), and rewrites **title + detail only** through `ActionRewordService::reword()` (prompt `ai.prompts.action_reword` — imperative verb-first title ≤100 chars; 2–3-sentence professional detail expanding telegraphic phrasing; `Owner:` suffix + `_provenance_` note preserved verbatim; never invents systems/dates/owners). Status, PIC, due date and priority are never touched — the row stays a draft. The `document_action_items` extractor prompt was also tightened to reword casual/Indonesian phrasing into professional English at extraction, so AI-extracted drafts come out polished already (the button still covers fast-parse and manual drafts).

- [x] Prompt `ai.prompts.action_reword` + `document_action_items` rewording tweak (config/ai.php) — 2026-10-14
- [x] `ActionRewordService` (title+detail only, failure → false → danger toast) — 2026-10-14
- [x] `rewordAction()` in `ActionImprovementFormSchema` + wired into relation manager + global resource — 2026-10-14
- [x] 5 tests (`ActionRewordTest`); pint; full suite 856/2,946 (1 known pre-existing RunAiAgentJobTest failure) — 2026-10-14
- [x] Docs (CLAUDE.md rows + baseline; this entry) — 2026-10-14
- [ ] Owner live check: a draft from Fast parse → Reword (AI) → title/detail professional + more detailed, other columns untouched; button hidden on pending rows; same button on the global Action Improvements page

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

### [PROJ-011] Prevention/Detection Program — governance scorecard, lag analytics, closure gate

**Status:** All 3 waves done (code complete; Wave 2 prod migrate pending owner)
**Priority:** P1
**Start Date:** 2026-10-13

#### Description
Owner request 2026-10-13 (grounded in the VP TechOps PDFs: `detection-response-gap-2026-ytd-v2.pdf`, `techops-pdr-action-plan-2026-v1.pdf`): make the Portal measure prevention/detection patterns, not just record incidents. Scope boundary: SLO burn-rate alerting / real-time anomaly detection are SRE-platform items, NOT this app. Approved as 3 independently-shippable waves. KPI substitution per the 2026-10-13 findings audit: PDR's "Negative MTTR = 0" is unimplementable (negative = day-unit encoding for fund-status rows) → replaced by "FL usable timing %".

**Wave 1 (done 2026-10-13, no migrations):** `Services/Governance/GovernanceKpiService` (single KPI implementation — register completeness, RCA-within-5d from the audits table with coverage, overdue pending action items, quarterly recurrence from `recurrence_data.is_recurring`, detection-lag medians by stream, usable-timing %, placeholder-time count; `aiCounts()` scope + current-year period; 15-min cache keyed on `dashboard_cache_version`) + GovernancePage (nav Reports, `manage incidents`) + `GovernanceKpiOverview` (RAG stat cards per PDR §10) + `DetectionLagChart` (median lag by quarter, FL vs NFL). Both widgets `$isLazy = false` — Filament lazy skeletons hide labels from first paint AND from `Livewire::test` page renders (that was the last Wave-1 bug).

**Wave 2 (done 2026-10-13, code complete — prod migrate pending owner):** `DetectedVia` + `FailedControl` enums; migration `2026_10_13_000001` (`detected_via`, `failed_control`, `detection_delay_days`, `start_time_known`); Incident casts + `startTimeKnown()`/`effectiveDetectionLagHours()`; Timeline-tab form fields + table badge columns + 2 filters; **closure gate in `IncidentObserver::saving()`** (ValidationException on any transition INTO Completed without RCA or stop-bleed — covers form, `update_status`, API, imports; 'N/A' root_cause passes as deliberate override; legacy Completed rows stay editable; documented exception to the "observers never block" rule); observer trigger list + 4 fields; export columnOptions (the existing BackedEnum coercion in both export mappers renders the new enums); GovernanceKpiService v2: timing KPIs via `effectiveDetectionLagHours()` + new FL unknown-channel % stat (PDR red >5%). The gate's test fallout: ~14 test files updated (completed fixtures now carry root_cause + stop_bleeding_at; deliberately-incomplete rows use `createQuietly` as legacy-row simulation).

**Wave 3 (done 2026-10-13):** overdue indicator on `ActionImprovementsRelationManager` (due_date renders danger/bold with an `overdue` description when pending + past due — RD-3; `isOverdue()` helper, display-only); quarterly recurrence KPI panel (shipped with Wave 1 — `ragZero(1)` RAG on the overview); `RootCauseFundParetoChart` on the Governance page — fund_loss per root_cause_category value, widget-aligned scope (`aiCounts()` + Completed), multi-category rows split evenly so bars sum to the total, uncategorised loss kept visible as `(uncategorized)`, top 8 + cumulative-% line (dual axis), cached `rca_fund_pareto_v1_*` on `dashboard_cache_version`. AI failed-control suggestion = follow-up, not built (hook point: `callAiForJson` + ViewIncident action, pattern `PostMortemService`).

#### Tasks
- [x] Wave 1: service + page + 2 widgets + blade + 11 tests (9 unit service / 2 feature page) — 2026-10-13
- [x] Wave 1: docs (CLAUDE.md dependency map + baseline 791/2670) — 2026-10-13
- [x] Wave 2: enums + migration + model + form/table + closure gate + exports + 16 tests (IncidentClosureGateTest 8 / DetectionFieldsTest 8) — 2026-10-13
- [x] Wave 2: docs (CLAUDE.md observer/model/governance rows + architecture-rule exception; findings.md RD-2/RD-4 closed) — 2026-10-13
- [x] Wave 3: overdue badge + Pareto chart + 4 tests (recurrence KPI was Wave 1) — 2026-10-13; baseline now 811/2705
- [x] Follow-up (owner rule 2026-10-13): fund-loss MTTR = elapsed calendar days, no `+1` (02→09 Oct = 7, was 8) — one-line `computeMttr()` change, +2 tests, baseline 813/2710; **owner runs `incidents:recalculate-metrics` on prod after deploy** (local dev already recalced)
- [ ] Wave 2: **owner runs `php artisan migrate --force` on prod** (owner-only guardrail), then backfill `detected_via` on Confirmed-loss rows (FL Unknown Channel % starts at 100%)
- [ ] Owner live check: Governance page RAG cards + Pareto match expectations on prod data (`docker compose restart app` for OPcache after deploy)

---

### [PROJ-015] Action Improvements page: incident visibility + grouping + comprehensive table

**Status:** Done
**Priority:** P2
**Start Date:** 2026-10-14

#### Description
Owner follow-up (2026-10-14, right after PROJ-014): "action improvement pages need which incident its on? can be group by incident? comprehensive table like incident pages? can edit from that page like now its already good. and know which people or group by people / team later." All in `ActionImprovementResource::table()` — one production file, no schema change:
- **Incident visibility**: `incident.no` badge (searchable, linked) + `incident.title` (searchable, linked, severity · status description) — relationship search is automatic in Filament 3.3 (`whereRelation` LIKE).
- **Grouping**: default view opens **grouped by incident** (owner choice via question — header "no — title", collapsible); group dropdown also offers PIC / Status / Priority. PIC group = exact email-combo (array cast → `getKeyFromRecordUsing`/`getTitleFromRecordUsing`, 'Unassigned' for empty); per-person/team grouping deferred (needs a real relation) per owner "later".
- **Comprehensive columns** (Incident-table style): Created, reminder + frequency, Last email (+ sent count), `result_note` under Completed — all toggleable, extras hidden by default. Edit stays the shared `ActionImprovementFormSchema` form.
- +4 tests/+13 assertions → baseline **839/2,838** (grouped default render, relationship search, PIC-combo group, toggleable columns via empty `toggledTableColumns` map).

#### Tasks
- [x] Table upgrade (groups + defaultGroup + incident columns + comprehensive toggleables) — 2026-10-14
- [x] 4 tests; pint; full suite 839/2,838 (1 known pre-existing RunAiAgentJobTest failure) — 2026-10-14
- [x] Docs (CLAUDE.md resource row + baseline; this entry) — 2026-10-14
- [x] Iteration 2 (owner 2026-10-14): incident groups open **latest-first** — `ListActionImprovements::mount()` seeds `tableGroupingDirection = 'desc'` (dropdown still flips it) and the incident Group orders by an `incident_date` subquery, not the `no` string (a higher no ≠ newer). Title columns shrunk: action title limit 35, incident.title 25. +1 test/+1 assertion → 840/2,839 — 2026-10-14
- [ ] Later (owner): per-person / team grouping — needs a real relation (e.g. PIC pivot to users or a team column), not the pic_email array cast

---

### [PROJ-014] Action Improvements: global page, closeout, priority, AI drafts

**Status:** Done (code complete; prod migrate pending owner)
**Priority:** P2
**Start Date:** 2026-10-14

#### Description
Owner picked the full menu (2026-10-14) after a gap review. Four big features + four quick wins, all shipped:
1. **Global Action Improvements page** (`ActionImprovementResource`, nav sort 3, `clipboard-document-check`) — every action across incidents, scoped `whereHas('incident', applyUserYearAccess())` (IssueResource precedent; policy as-is: viewAny = `view incidents` OR `access api`). Columns: title+detail, incident link, priority/escalation/status badges, overdue-styled due date, pic_email, completed_at + closer name. Same status/priority/overdue filters as the relation manager; no bulk actions.
2. **Closeout tracking** (migration `2026_10_14_000001`) — `completed_at`/`completed_by`/`result_note`; `ActionImprovementObserver::saving()` stamps on transition INTO done (`??=` — re-saves don't re-stamp) and clears both on reopen; `saveQuietly` paths (reminder recording) never stamp.
3. **Priority field** — low/medium/high/critical, **optional, DB default 'low'** (owner-specified). Constants `ActionImprovement::PRIORITIES`; form default low, badge colors via `ActionImprovementFormSchema::priorityColor()`.
4. **AI "Suggest Actions" button** — relation-manager header action (`manage incidents` gate) dispatches `GenerateActionSuggestionsJob` → `ActionSuggestionService::generate()` (one `callAiForJson` over `generateCompact()` + retro when present; prompt `ai.prompts.action_suggestions`) → ≤8 draft rows (status **draft**, due +30d, reminder off), titles deduped case-insensitively against existing rows (re-run = 0 new); requester gets an in-app `ActionImprovementsSuggested` notice (success/0-created/failed variants). Drafts stay out of reminders/overdue (existing `status = 'pending'` gates).
Quick wins: relation-manager + global filters (status/priority/Ternary overdue), dashboard widget Overdue stat ("N escalated to a manager") + Avg Days to Close, escalation badge columns (`L1–L3` + last-escalated date, visible only when > 0), **'Cancelled' status** (values-only — reminder/escalation/governance-overdue all `pending`-gated so cancelled drops out with zero extra code; `registerCompletenessPct()` deliberately unchanged — it counts action-item EXISTENCE, so a cancelled item still satisfies register completeness).

Shared form single-source: `ActionImprovementFormSchema` (schema + status/priority color maps) consumed by both the relation manager and the global resource — the two surfaces cannot drift.

Bug caught by the new tests before merge: the widget's aging/closeout queries cloned a builder that had already `groupBy('status')` — the clone dragged the GROUP BY into them (aging returned the FIRST GROUP's numbers → overdue would read ~0 in prod; closeout hydration picked an arbitrary group row → null completed_at crash). Fixed with a fresh `$scoped()` builder per stat; recorded as edit-safety rule 6. API write routes stay disabled (read-only by security decision) — the Store/Update request rules (`status: pending,done,cancelled`; `priority in:low,medium,high,critical`) are validated directly so they're covered whenever writes re-enable.

#### Tasks
- [x] Wave 1: migration + model constants/casts/relations + observer closeout stamping + API request rules — 2026-10-14
- [x] Wave 2: ActionImprovementFormSchema + relation-manager rewrite (filters, priority/escalation columns, Suggest Actions) + global ActionImprovementResource/List/Edit — 2026-10-14
- [x] Wave 3: widget 4-stat layout (Overdue + escalated description, Avg Days to Close; groupBy-clone bug fixed) — 2026-10-14
- [x] Wave 4: config prompt + ActionSuggestionService + job + notification + getNotificationType arm — 2026-10-14
- [x] Tests: `ActionImprovementLifecycleTest` (11) — closeout stamp/clear/quietly, priority default + cancelled-reminders, request rules, global page year-access/overdue filter/escalation badge, relation manager render, widget aging/closeout, AI dedupe + re-run 0 + requester notify — 2026-10-14; baseline 835/2825
- [x] Docs: CLAUDE.md dependency-map rows + business rules + edit-safety rule 6 + baseline — 2026-10-14
- [x] Iteration TBD (owner 2026-10-14: "some action improvement have TBD date or dont know can we make choose TBD ?") — `due_date` nullable (migration `2026_10_14_000002`): null = TBD, chosen by clearing the DatePicker (helperText says so), rendered `TBD` via column placeholders on both surfaces; TBD rows sort last on the global page (`orderByRaw('due_date IS NULL')`); **no more invented +30d dates** — `DocumentActionItemService::resolveDueDate()` and the WarRoom retro drafts now store TBD when no real date is known; reminders/escalation/overdue/KPIs already exclude TBD via SQL null semantics; reminder notification null-guarded ("Due date not set (TBD)" — was "0 days remaining"); `ActionImprovementTbdTest` (3) → 859/2961 — 2026-10-14
- [x] TBD iteration 2 (owner 2026-10-14: "we need to know which one is TBD so we can also tracked") — `tbd` TernaryFilter on both tables (null/not-null due), pending TBD rows warning-colored, widget Pending card carries "N TBD (no due date)" (pending only — drafts/dated don't count), overdue filter's not-overdue branch now includes TBD (`orWhereNull` — no date ≠ past due; it silently dropped pending TBD rows before); +2 tests → 861/2966 — 2026-10-14
- [ ] Owner runs `php artisan migrate --force` on prod (same run now covers `2026_10_13_000001`–`2026_10_14_000002`); local dev already migrated
- [ ] Owner live check: global page nav + filters, done → completed stamp, AI Suggest Actions on a real incident

---

### [PROJ-013] PIC directory on users + org-tree escalation

**Status:** Done (code complete; prod migrate pending owner)
**Priority:** P2
**Start Date:** 2026-10-13

#### Description
Owner request 2026-10-13 ("cari nama" + "escalate ke atasnya seperti org tree"), redirected mid-build from a separate `pic_contacts` table to **reusing the users table** (owner: "Reuse user login table... make them cannot login... give access"). Every action-improvement PIC email is auto-saved as a login-disabled directory user by `User::upsertFromPicEmails()` (single home; observer `saving()` normalizes `Name <email>` suggestion tags to bare emails, existing users never touched); the relation-manager TagsInput now suggests users by name ("Name <email>"). Login gate: `users.can_login` defaults TRUE in migration `2026_10_13_000003` so every existing non-expired user keeps logging in; directory rows are `can_login=false` (random password) — flip the toggle in User Management to grant access; API/Sanctum routes never pass the panel gate. `manager_id` self-relation is the org tree, curated in UserResource (Select + Login column). Overdue escalation in `SendActionImprovementReminders`: level = `min(daysOverdue/14, 3)` — ≥2wk → manager, ≥4wk → one higher, capped at 3; fires every unreached rung on first run (deep-overdue rows inform each rung passed), stops at chain depth, one `ActionImprovementEscalated` mail per rung that deliberately bypasses mail preferences (owner mandate) + in-app rows; `escalation_level`/`last_escalated_at` persisted. Intended behavior change: directory PICs (previously non-User emails that received NOTHING) now get reminder + assigned emails like everyone.

#### Tasks
- [x] Migration 000003 (can_login default true, manager_id self-FK, escalation columns) — 2026-10-13
- [x] User: upsertFromPicEmails + parsePicEmailTag + manager()/ancestorAt + canAccessPanel gate (strict `=== false`) — 2026-10-13
- [x] Observer + command swap to users directory; escalation ladder cumulative — 2026-10-13
- [x] Form suggestions + validation; UserResource can_login/manager fields + columns — 2026-10-13
- [x] 6 tests (UserDirectoryEscalationTest) — 2026-10-13; baseline 823/2767
- [x] Iteration 2 (owner 2026-10-13): pic_email TagsInput → incident-picker-style Select dropdown (options keyed by email over human users, stored `pic_email` unchanged) + built-in "+" createOption button → inline new contact via `upsertFromPicEmails` (login-disabled); +1 test → 824/2777
- [ ] Owner runs `php artisan migrate --force` on prod (same run covers 000001–000003), then curates manager chains in User Management (empty chain = no escalation)

---

### [PROJ-012] Action Improvement reminder tracking + frequency throttle

**Status:** Done (code complete; prod migrate pending owner)
**Priority:** P2
**Start Date:** 2026-10-13

#### Description
Owner request 2026-10-13 ("auto save email that have been sended"). Every reminder email sent for an action improvement is now recorded on the row: `last_reminded_at` + `reminders_sent` (migration `2026_10_13_000002`), written by `ActionImprovement::recordReminderSent()` from both send paths (08:00 scheduled command + relation-manager Email button) whenever `ReminderMail::send()` actually mailed someone (returns int now — 0 means everyone's mail preference was off, and then nothing is recorded). The relation manager shows a "Last email" column with the sent count. `reminder_frequency` (weekly/biweekly) — a form field nothing ever read — now throttles the daily overdue batch via `last_reminded_at`; null frequency stays daily. Bonus bug fix found by the new tests: the command's `usersByEmail` prefetch ran AFTER the due-soon loop, so due-soon emails only ever reached incident PICs, never `pic_email`-matched users (existing tests masked it by attaching pics to the incident).

#### Tasks
- [x] Migration + model (recordReminderSent) + ReminderMail int return — 2026-10-13
- [x] Command: throttle + record both batches + prefetch-order fix — 2026-10-13
- [x] Relation manager: record + honest toasts (mailed vs preference-filtered) + Last email column — 2026-10-13
- [x] 4 tests (ActionImprovementReminderTrackingTest; first-ever coverage of the Email button) — 2026-10-13; baseline 817/2736
- [ ] Owner runs `php artisan migrate --force` on prod (one run now covers `2026_10_13_000001`–`000003`)

---

### [PROJ-010] Multi-PIC incidents + working Netcore email

**Status:** Done (code complete, awaiting prod deploy)
**Priority:** P1
**Start Date:** 2026-10-01

#### Description
Owner request 2026-10-01: enable Netcore email (was stuck on `MAIL_MAILER=log` — everything written to laravel.log, nothing sent); auto-email each PIC on assignment ("you have been assigned"), PIC becomes multiple people, From address (e.g. `noreply-techrisk@dana.id`) + Netcore settings editable from the app, API key visible, send errors surfaced. Delivered: T1 `MailSettings::apply()` runtime activation from Settings (no .env editing) called at boot + on save (with `queue:restart`); T2 EmailSettings rework (editable From, status panel, real API key in the field); T3 Send Test Email action (failure notification carries the exact exception) + Recent Email Failures panel (last 5 `failed_jobs` matching Netcore); T4 multi-PIC cutover — `incident_pic` pivot replaces the `pic_id` column in ONE migration (copy + drop, no dual-write), `IncidentPic` pivot hooks own assignment email to each newly attached PIC + RAG re-index + AI cache clear (pivot sync fires no model events), all consumers swept (Filament form/filter/column, reminder commands, exports, charts, AI services, API `pic_ids[]` with legacy `pic_id` mapped one release). **Prod notes: the T4 migration DROPS `pic_id` — owner-only guardrail (`php artisan migrate --force`); after deploy `docker compose restart app queue`; Netcore console must have noreply-techrisk@dana.id as an approved sender.**

#### Tasks
- [x] T1 runtime mail activation + From override — 2026-10-01
- [x] T2 EmailSettings editable From + status panel + real key — 2026-10-01
- [x] T3 send-test-email action + recent failures panel — 2026-10-01
- [x] T4 multi-PIC pivot cutover + full consumer sweep — 2026-10-01
- [x] T5 CLAUDE.md dependency map (IncidentPic + MailSettings rows) + this entry — 2026-10-01
- [ ] Owner live check after deploy: Email Settings → toggle ON, key visible, From set, Send Test Email arrives from noreply-techrisk@dana.id; assign 2 PICs → both emailed; re-save unchanged PICs → no new email

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

### [PROJ-017] Microsoft (Entra ID) SSO Login

**Status:** In Review (code complete, awaiting Azure credentials + owner e2e check)
**Priority:** P1
**Start Date:** 2026-11-05

#### Description
Owner request 2026-11-05: "Sign in with Microsoft" on the Filament login page, tenant-restricted to the company Entra tenant. Owner decisions: first-time Microsoft logins are **auto-created but role-less** — they see nothing until an admin grants a role in User Management (the existing `User::canAccessPanel()` gate enforces this; zero new gating code); existing users matched by email keep their current access (role, `can_login`, `access_expiry`) untouched. Socialite's state (CSRF) validation kept; scopes `openid profile email` only.

#### Technical Approach
- `laravel/socialite` + `socialiteproviders/microsoft`, driver registered via `SocialiteWasCalled` listener in `AppServiceProvider::boot()`
- `config/services.php` `microsoft` entry with `tenant` = `MICROSOFT_TENANT_ID` (single-tenant authorize URL); env: `MICROSOFT_CLIENT_SECRET` holds the secret's **Value**, not its Secret ID
- Guest-only routes `/auth/microsoft/{redirect,callback}` → `MicrosoftLoginController`: case-insensitive email match (`whereRaw('lower(email) = ?)`)`, JIT users get a random unused password (column NOT nullable, same as `upsertFromPicEmails`) + `email_verified_at` now fillable; gate-fail → logout + flash on login page
- Login button + flash via `PanelsRenderHook::AUTH_LOGIN_FORM_AFTER` render hook + `resources/views/auth/microsoft-sso.blade.php`; `bootstrap/app.php` `redirectUsersTo` keeps authenticated users off the guest routes away from the 404 at `/`
- No `microsoft_id` column (single-tenant + unique email make it redundant)

#### Tasks
- [x] Implementation + `MicrosoftSsoTest` (5 tests / 22 assertions) — 2026-11-05
- [ ] Azure: register both redirect URIs (Web platform): `http://localhost:8000/auth/microsoft/callback` + `https://techrisk.paas.dana.id/auth/microsoft/callback`; fill the 4 `MICROSOFT_*` env vars (dev and prod)
- [ ] Owner e2e: new-email login → "admin must grant access" flash → grant `user` role → login lands on dashboard; password login still works

#### Blockers
- None (waiting on Azure-side env credentials)

---

## Completed Projects

### [PROJ-005] Export Redesign — Executive Report, Group By, Form Validation
- **Completed:** 2026-09-29
- **Outcome:** 4-preset export (Executive/GroupBy/AllTabs/Custom) + filters + validation hardening; 529/1765 green

### [PROJ-001] Initial SOP and Documentation Setup
- **Completed:** 2026-02-02
- **Outcome:** Established documentation framework for continuous improvement

---

*Last Updated: 2026-10-13*
