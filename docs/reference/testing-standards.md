# Testing Standards (Reference)

Detailed testing patterns, examples, and checklists. Read this when writing tests.

---

## Test Organization

```
tests/
├── Unit/              # Isolated component tests
│   ├── Models/
│   ├── Services/
│   └── Helpers/
├── Feature/           # Integration tests
│   ├── Api/
│   ├── Web/
│   └── Console/
└── Pest/              # Pest tests (if using Pest)
```

## Unit Testing

Test all public methods in Services. Mock external dependencies. Use data providers for multiple scenarios. Aim for 80%+ code coverage.

```php
class IncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_incident_with_valid_data(): void
    {
        $service = new IncidentService(
            $this->mock(NotificationService::class)
        );

        $incident = $service->createIncident([
            'summary' => 'Test Incident',
            'severity' => Severity::P1,
        ]);

        $this->assertDatabaseHas('incidents', [
            'summary' => 'Test Incident',
        ]);
    }
}
```

## Feature Testing

Test API endpoints comprehensively. Cover validation errors, authorization/permissions, happy path and error paths.

```php
class IncidentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_incidents(): void
    {
        $response = $this->getJson('/api/v1/incidents');
        $response->assertUnauthorized();
    }

    public function test_can_create_incident(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/incidents', [
            'summary' => 'New Incident',
            'severity' => 'P1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.summary', 'New Incident');
    }
}
```

## PHPUnit Configuration

Use SQLite in-memory for fast tests. Configure in `phpunit.xml`:

```xml
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

## Test Data Management

Use **Factories** for test data generation. Define realistic states in factories. Use `seed()` for reference data.

```php
User::factory()->create([
    'name' => 'Test User',
    'email' => 'test@example.com',
]);
```

## Testing Checklists

### For New Features
- [ ] Unit tests for service methods
- [ ] Feature tests for API endpoints
- [ ] Validation tests for form requests
- [ ] Permission/authorization tests
- [ ] Observer event tests
- [ ] Edge case coverage

### For Bug Fixes
- [ ] Reproducible test case for bug
- [ ] Verify fix with test
- [ ] No regression in existing tests

### Filament Resource Testing
- [ ] Test all form validations
- [ ] Verify relationship managers work
- [ ] Test filters and sorting
- [ ] Check create/edit/delete operations
- [ ] Verify export functionality

### Browser Testing
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Mobile responsive (iOS/Android)

## Continuous Integration

- Run tests on every PR
- Enforce code coverage thresholds
- Run security audits
- Verify code style compliance

## Test Baseline History

**Current baseline: 1020 tests / 3,688 assertions (2026-10-30, two-step full-sync flow round — the UI dry run/Preview deleted deliberately (recorded below); the known env failures below are unchanged and excluded from "green" judgement).** A change that drops this count or its assertions is a regression, not a refactor — unless the delta is a deliberate feature deletion recorded here.

- 811 / 2,705 (2026-10-13; delta from 791/2,670 = Wave 2 +16: `IncidentClosureGateTest` (8 — transition-into-Completed blocked without RCA/stop-bleed incl. create-as-Completed, category/'N/A' pass, legacy rows still editable) + `DetectionFieldsTest` (8 — enum casts, startTimeKnown/effectiveDetectionLagHours semantics, export columns, cache bump). Wave 3 +4: `RootCauseFundParetoChartTest` (Pareto split/order/widget-aligned scope + overdue relation-manager flag). The gate forced fixture updates across ~14 test files (completed rows now carry root_cause + stop_bleeding_at; deliberately-incomplete rows use `createQuietly` as legacy-row simulation). Wave 1 (+11 governance scorecard) is in the 791/2,670 line.
- 813 / 2,710 — fund-loss MTTR elapsed-days rule (owner 2026-10-13) +2 tests/+5 assertions.
- 817 / 2,736 — action-improvement reminder tracking + reminder_frequency throttle (owner 2026-10-13) +4 tests/+26 assertions.
- 823 / 2,767 — users-table PIC directory + org-tree escalation (owner 2026-10-13) +6 tests/+31 assertions (forced `ActionImprovementEndpointTest` to add broadcast-to-log — directory PICs now get the assigned notification).
- 824 / 2,777 — PIC-picker dropdown + "+" inline contact (+1 test/+10 assertions, iteration 2).
- 835 / 2,825 — PROJ-014 (2026-10-14) +11 tests/+48 assertions (`ActionImprovementLifecycleTest`: closeout stamp/clear/saveQuietly, priority default + cancelled-out-of-reminders, API request rules — write routes stay disabled by security decision so rules are validated directly, global page year-access + overdue filter + escalation badge, relation-manager render, widget overdue/escalated/avg-days, AI dedupe + re-run-0 + requester notification).
- 839 / 2,838 — PROJ-015 (2026-10-14) +4 tests/+13 assertions (grouped-by-incident default view, relationship search, PIC-combo group + Unassigned, comprehensive toggleable columns).
- 840 / 2,839 — PROJ-015 iteration 2 (owner 2026-10-14) +1/+1 (incident groups default latest-first by incident_date, not the "no" string).
- 847 / 2,875 — PROJ-016 (2026-10-14) +7/+36 (`DocumentActionItemsTest`: mangled-RCA parser, section boundaries/prose, empty variants, row-action drafts + idempotence + visibility gates; `ActionSuggestionService` refactor onto the shared loop is behavior-preserving — its PROJ-014 tests unchanged).
- 851 / 2,917 — PROJ-016 iteration 2 (owner 2026-10-14) +4/+42 (AI extractor on `add_action_items`: model picker over all available models, `ai.prompts.document_action_items` mapping title/detail/priority/due_date/owners, per-item strict due-date validation, owner name→user auto-assign, parser path preserved).
- 856 / 2,946 — PROJ-016 iteration 3 (owner 2026-10-14) +5/+29 (`ActionRewordTest`: draft reword via the shared row action (title+detail only), hidden for pending rows / without permission, failure keeps original + warns, available on the global page; `document_action_items` prompt now rewords at extraction).
- 859 / 2,961 — TBD due dates (owner 2026-10-14) +3/+15 (`ActionImprovementTbdTest`: edit clears date → TBD persists, TBD placeholder renders on the global page, reminder command skips TBD rows entirely; +30d fallback tests rewritten to assert TBD — DocumentActionItems ×2, lifecycle AI-suggest assertNull).
- 861 / 2,966 — TBD tracking iteration (owner 2026-10-14) +2/+5 (tbd filter isolates null-due rows + not-overdue keeps TBD visible; widget counts pending TBD only — drafts/dated excluded).
- 864 / 2,978 — export MTBF filter scoping (owner 2026-10-23) +3/+12 (fund-status-filtered export telescopes gaps in all three sequence implementations: Custom `IncidentTableExport` mtbfScope ctor arg, Quarterly Report `QuarterlyCaseSheet` full-filtered-set scope, All-Tabs `SingleIncidentSheetExport` per-tab base off its own query; footer = scoped mean; case tabs share the full-set sequence, not per-tab; unfiltered legacy behavior pinned by the no-scope asserts).
- 869 / 2,991 — widget-aligned export baseline (owner 2026-10-23) +5/+13 (`ExportWidgetBaselineTest`: baselineQuery defaults to Confirmed loss + Non fundLoss (widget MTBF scope), explicit f_fund_status wins, other f_* filters stack; All-Tabs All Cases/Completed scoped while Recovered/Fund-status/On Going sheets keep every fund status; Quarterly All Cases + quarter tabs scoped, Recovered tab keeps Fully-recovered rows, sequence telescopes).
- 891 / 3,094 — action-improvement evidence + cancellation reason + `tbd` status (owner 2026-10-26) +22 tests/+112 assertions measured per file over 869/2,991: `ActionImprovementEvidenceTest` (+8/56 — encrypted upload round-trip, ciphertext ≠ plaintext on disk, key row + incident `file_uploaded` audit, delete removes file + key + `file_deleted` audit, download 200 + Content-Disposition + decrypted body / 403 / 404 / 500), `ActionImprovementTbdStatusTest` (+8/47 — tbd with past due + reminder on sends nothing and stays escalation-free, widget ignores tbd, cancel-without-reason blocked / with reason persists / reason not required for pending-done, tbd in both status filters + gray badge, `send_reminder` hidden for tbd), `WebRouteTest` (+6/+8 — evidence download route block mirroring the documents block, `createEncryptedEvidence()` helper), `ActionImprovementLifecycleTest` API-rules test extended in place (+0/+1: `status: 'tbd'` passes, `'delayed'` still fails). A `view dashboard widgets` permission feature (+6/+6, `DashboardWidgetPermissionTest`) briefly rode this branch, was reverted during review as out-of-plan, then **restored with owner sign-off** — the owner requested it directly in the same session (2026-10-26) and the review agent lacked that context; final tree carries BOTH features, measured full-suite 897/3,100 (+6/+6 over the 891/3,094 evidence-only figure, same 7 env failures). Full-suite run on this tree: 6 reverb-broadcast errors + 1 `RunAiAgentJobTest` failure — the documented pre-existing env failures, unchanged; 891/3,094 is the measured count with those present (test count = 869+22 exactly; the assertion total sits 9 under the per-file sum 2,991+112=3,103 because the 7 env-failure tests error mid-test and their partial assertion counts drift between runs — compare against the measured figure, not the arithmetic one).
- 900 / 3,107 — export baseline null-fund amendment (owner 2026-10-27) +3/+7 (`ExportWidgetBaselineTest`: a null-fund_status metric-eligible row joins the default export baseline; an explicit `f_fund_status` pick still excludes null; the Quarterly Report's All Cases sheet carries the null row at sheet level). `widgetFundScope()` now **delegates to `Incident::excludedFromCounts()`** — the tile's exact predicate (null OR not-excluded) instead of a re-encoded allow-list, so the export can never drift from the tile again (prod 2026: widget 39 vs export 38, widget ruled correct). Same 7 env failures unchanged.
- 905 / 3,124 — severity footer on every incident-listing export tab (owner 2026-10-27) +5/+17 (`SeverityFooterTest`: Quarterly All Cases + Fund Loss tab footers below the pinned Total Cases row, All-Tabs below the summary block, Group-By category sheet — `PerCategorySheet`'s first footer ever, Custom from the Collection path; Avg MTTR/fund counts of each tab's OWN rows, null-fund row in severity but no fund bucket). `WritesSeverityFooter` trait + 6 call sites; the two grouped count clones carry `reorder()` — adversarial review + local MySQL repro showed tab builders' inherited `ORDER BY` fails `ONLY_FULL_GROUP_BY` (error 1055) on prod MySQL while SQLite tests stay green. `ExportMtbfReconciliationTest` ×5 and `ExportCurrencyFormatTest` ×1 summary anchors shifted `getHighestDataRow()` → `−3` — the footer now extends the sheet below the summary values row (assertion intent unchanged). Same 7 env failures unchanged; assertion count drifts ±1 between runs per the note above.
- 906 / 3,132 — footer v3: merged single-line banners (owner 2026-10-27, iteration 3 of the entry below) +1 test/+1 assertion measured (adversarial review's second round added `test_day_based_only_tab_banner_shows_dash_for_minutes_bucket` — a day-based-only tab pinned directly via `SingleIncidentSheetExport` must render `-` for the empty minutes bucket, closing the untested ternary branch) (`SeverityFooterTest` rewritten in place: the 5-row per-column grid became two MERGED banner rows spanning max(table width, H) — a grid inheriting the incident table's column widths read badly per the owner; banner strings asserted in full (`P1: 3 | P2: 1 | … X4: 0`, `Avg MTTR (mins): 52.5 | Avg MTTR (days): 3 | Fund Loss: 3 | Non Fund Loss: 1`) plus `getMergeCells()` pins `A9:L9` quarterly / `A12:H12` narrow Custom — fewer cells to pin than the grid, hence the assertion drop). Merged cells CLIP (no overflow) so the H floor keeps narrow Custom sheets readable. Summary anchors reverted `−6` → `−3` ×7 (`ExportMtbfReconciliationTest` ×6 + `ExportCurrencyFormatTest` ×1 — banners are 2 rows again). Stats math unchanged from the previous entry. Same single `RunAiAgentJobTest` env failure (reverb ones didn't fire, Docker up).
- 905 / 3,148 — footer restyle + Avg-MTTR sign split (owner 2026-10-27, same-day iteration of the entry above) +0 tests/+24 assertions measured (`SeverityFooterTest` rewritten in place for the 5-row block: severity header/counts rows + stats header/values rows with `Avg MTTR Non-Fund Loss (mins)` / `Avg MTTR Fund Loss (days)` / `Fund Loss` / `Non Fund Loss`; a `mttr => -3` Confirmed-loss fixture pins the days bucket and proves it never pollutes the minutes average; fund-loss tab asserts `-` in its empty minutes bucket). Split follows the storage convention (owner 2026-10-13): mttr ≥ 0 averaged as minutes, mttr < 0 averaged abs() as days — Builder and Collection paths identical. `fromArray(..., strict=true)` is load-bearing: the default loose compare treats `0 == null` and silently drops zero-count cells (caught by the suite on first run). Summary anchors `−3` → `−6` in `ExportMtbfReconciliationTest` ×6 + `ExportCurrencyFormatTest` ×1 (the block is 5 rows tall). This run reproduced only the 1 `RunAiAgentJobTest` env failure (the 6 reverb-broadcast errors didn't fire with Docker up), so the assertion total counts their completed tests — compare against 3,148 on a Docker-up run, 3,124-ish otherwise.
- 931 / 3,233 — Notion MCP workspace connection + sub-page sync feature branch (2026-10-27/28) +70 tests (OAuth route gating incl. guest 401 / non-permission 403 / admin pass, client token storage + lazy refresh + needsReconnect flag lifecycle, sync watermark semantics + page-ID/title dedupe + per-row poison-pill isolation + tombstone recovery, AI-chat Notion tool wiring, `NotionSettings` page).
- 936 / 3,251 — ultracode audit fixes (owner request 2026-10-29) +5 tests/+18 assertions (`NotionIssueSyncServiceTest`: poison-pill isolation + watermark hold at first failure + fixed-row re-import + tombstone survives rolled-back restore + per-run title-pluck query count; `NotionMcpClientTest`: failed refresh sets needsReconnect + honest status, fresh tokens clear it; `NotionOauthRoutesTest`: denied callback + missing session redirect to settings with a notice).
- 981 / 3,419 — merge of upstream main (906/3,132: export footer banners, action-improvement evidence + cancellation + TBD status) into the Notion branch +0 new tests — the delta is both sides' features stacking (906 + 75 Notion tests = 981). Only the 2 env failures fired.

Environmental notes: 6 reverb-broadcast errors reproduce only outside the test env, and 2 pre-existing failures reproduce on clean main — `RunAiAgentJobTest::test_include_corpus_skips_block_when_corpus_never_built` (re-verified 2026-10-12 on stashed clean main, again 2026-10-23) and `ChatPersonaParityTest::test_web_search_toggle_fences_retrieved_results` (re-verified 2026-10-12).
- 996 / 3,504 — first-sync duplicate protection (owner 2026-10-29) +15 tests/+85 assertions (`NotionIssueSyncServiceTest` +5: normal sync never restores deleted issues, dry-run-with-restore previews without touching anything, a Notion row matching a manual Incident-classified row is skipped, dry-run itemizes every row outcome, the boundary re-read emits no skip line; `NotionSyncActionTest` ×4 new: run defaults to normal / passes the full-sync flag / preview is a read-only full dry run with the capped itemized body / the shared modal carries the checkbox + warning; `NotionSettingsSyncButtonsTest` ×3 + `NotionSyncButtonTest` +1: both surfaces' Sync-now + Preview wired through Livewire header actions; `NotionIssueMapperTest` +2: title dedupe matches Incident-classified rows). Same-spec rewrite: `IssuesImporterTest::test_matches_incident_classified_rows_too` (the CSV importer inherits the wider dedupe scope — the old Issue-only test pinned the superseded spec). Layout pivot: Notion Settings' Connect/Disconnect/Preview/Sync-now moved from form-schema actions to `getHeaderActions()` — page-level `callAction` resolves header actions only. Only the 2 env failures fired.
- 1004 / 3,523 — sync UI placement + status round (owner 2026-10-29, second pass) +8 tests/+19 assertions (`NotionSettingsStatusTest` ×7 new: the header button reads "Connect" before any connection and "Reconnect" once a token exists — including failed refreshes — and the status line is color-coded via `connectionState()`/`connectionStatusHtml()`: connected green+bold, not-connected/reconnect-required red, renewal-pending amber; `NotionSettingsSyncButtonsTest` +1 header-structure test + rewritten in place: Sync now/Preview moved OUT of the page header INTO the "Sync issue from Notion" section as form-component actions, dispatched in tests via their derived ActionContainer keys `data.syncNowAction`/`data.previewSyncAction`; `NotionSyncButtonTest` same-count rewrite: the Issue-list button is now NORMAL-SYNC-ONLY — the full-sync checkbox lives on Notion Settings, and posted `restoreDeleted` data is ignored). Only the 2 env failures fired.
- 1007 / 3,550 — preview honesty round (owner 2026-10-30) +3 tests/+27 assertions (`NotionIssueSyncServiceTest` +1: a dry run paginates past the Notion engine's per-query row cap — prod returned exactly 100 rows of a larger database, silently capping the preview's counts; a full 100-row first page is followed by a moving `createdTime >= tail` query, tail re-read URL-deduped, → 102 counted). Rewrites: the skip message is a per-reason breakdown (`; M already synced; K duplicate title(s); B blank title(s); U unreadable URL(s); F failed`, created=0 leads "No new issues") — the lumped "already synced/duplicate/blank" read as trouble when almost all of it was the normal already-synced state; already-synced rows are a COUNT, never a line (the itemized list keeps findings only: would-import, duplicate, blank, would-restore, FAILED). `NotionSyncActionTest` +1/rewritten: the Preview button's output moved from a capped toast to `previewModalContent()` — the read-only full dry run rendered in a centered scrollable modal (view `filament.actions.notion-dry-run`), footer Close + "Continue full sync" (the submit runs the real `sync(restoreDeleted: true)` — asserting both service calls: the dry run fires at modal mount, the real sync at submit). SCAN_LIMIT 200 → 100 (the engine's observed cap) — real syncs drain `ceil(N/100)` runs. Only the 2 env failures fired.
- 1014 / 3,648 — watch-ID editor round (owner 2026-10-30) +7 tests/+98 assertions (`NotionWatchIdEditorTest` ×7, all via form-component actions on the new ActionContainer keys `changeWatchIdAction`/`saveWatchIdAction`/`cancelWatchIdAction`): the "Notion page or database ID" field is never open for direct editing — read-only mono display by default (the edit input renders as Filament's EMPTY hidden wrapper when locked, so its label is absent — asserting `data.watch_id_input` absent would false-fail on the wrapper's own `wire:key`), Change unlocks a pre-filled input, Save is disabled while the input is empty or unchanged (`assertFormComponentActionDisabled` + a server-side no-op guard — the unchanged save sends no notification), a value with no 32-hex ID inside shows the inline error and saves nothing (edit mode + input intact), Cancel discards without saving, and a saved change persists the normalized bare ID, locks the field, and shows the ✓ Saved badge. +1 layout test: Change/Sync now/Preview share ONE actions row (`substr_count(html, 'fi-fo-actions') === 1` — the editor buttons and the sync buttons were two stacked rows; while editing, Save/Cancel take Change's slot in the same row). Structural deletions: `NotionSettings::save()` and the page-level "Save Settings" submit button are GONE (the inline editor owns the save — one save path); `normalizePageId()` is now public (shared by the Save action's disabled closure and `saveWatchId()`). Only the 2 env failures fired.
- 1004 / 3,523 — banner gains Avg MTBF (upstream main, owner 2026-10-30, "for average MTBF can you make it good also"; merged after the watch-ID editor round — developed in parallel on main while PR #4 was open, +0 tests/+0 assertions measured) (`SeverityFooterTest` banner strings extended IN PLACE: banner 2 gains ` | Avg MTBF (days): x` between MTTR-days and Fund Loss). Mirror-value design — each call site passes its OWN summary value so banner and summary can't drift: `QuarterlyCaseSheet` its footer-row `$avgMtbf`, `SingleIncidentSheetExport` `stats['avgMtbf']`, `IssuesMetricSheetExport` its mtbf sheet's `$metricValue` (mttr sheets pass null), `IncidentTableExport` its displayed-column value; stored-metric sheets (`PerCategorySheet`, `ExecutiveDataSheet`) pass null and the segment is omitted (the group-by test pins its absence). Merge floor H→L — the stats line grew to ~95 chars and merged cells CLIP; the narrow-Custom merge pin moved `A12:H12` → `A12:L12`. MTBF expectations pin each preset's sequence semantics: quarterly All Cases 10.25 (scoped baseline chain), Fund Loss tab 13 (its rows read gaps off the BASELINE chain 5+21 — sequence semantics, not per-tab gaps), All-Tabs 10.25 (per-tab), Custom 7.5 (no mtbfScope ctor arg → legacy GLOBAL chain, the excluded P3 telescopes to 10: 10+5+5+10 / 4), day-based-only tab 0. Only the 1 documented `RunAiAgentJobTest` env failure fired.
- 1020 / 3,681 — full-sync restore picker (owner 2026-10-30) +6 tests/+32 assertions, measured full-suite on this tree against a stashed clean main re-run (1014 / 3,649 — the documented ±1 assertion drift; both env failures present in both runs) (`NotionIssueSyncServiceTest` +4: a full sync restores ONLY the picked pages — the unpicked tombstone survives and its page is NEVER looked up (an unexpected lookup fails the mock), an empty pick restores nothing while the new-row window still runs, `restorableIssues()` lists exactly what a restore would import — gone-from-Notion, duplicate-title, and blank-title pages excluded, label `{title} ({date}, {severity})` — and returns nothing when Notion is disconnected; `NotionSettingsSyncButtonsTest` +1 net: picks pass through to `sync(restoreDeleted: true, restorePageIds: [...])`, an empty pick submits `[]` = deliberate restore-nothing, the picker renders the restorable list once the full-sync checkbox is ticked, the Preview modal is Close-only; `test_continuing_from_the_preview_modal_runs_the_real_full_sync` DELETED with the feature — a deliberate feature deletion, recorded here). `NotionSyncActionTest` +1: `run()` forwards the pick, and only null = no-picker = restore-all. Contract change: `sync()` gains `?array $restorePageIds` (null = all — the console/scheduler path; `[]` = none; a list = only those) — every sync mock across the three suites updated to the 3-arg shape. Filament mechanics learned: a hidden form field does NOT dehydrate (the picks only reach the action when the CheckboxList renders — so a Notion listing failure degrades safely to restore-nothing), and `CheckboxList::options()` evaluates even while the field is hidden (hence the page-level memo `restorableIssueOptionsCache` — one Notion fan-out per page load, not per render). Only the 2 env failures fired.
- 1022 / 3,697 — picker polish round (owner 2026-10-30, the 9-story selection spec) +2 tests/+16 assertions (`NotionSettingsSyncButtonsTest` +2: "Continue full sync" is BACK on the preview modal — but it SWAPS the preview for the full-sync PICKER with the checkbox pre-ticked, never syncing by itself — `test_continuing_from_the_preview_opens_the_full_sync_picker_pre_ticked` pins `assertFormComponentActionMounted('syncNowAction', …)` + `assertFormComponentActionDataSet(['restoreDeleted' => true])`; `test_the_full_sync_picker_carries_a_select_all_toggle` pins `CheckboxList::bulkToggleable()`. Mechanics learned: a server-side `$livewire->dispatch()` does NOT run same-component listeners within the request; the working swap is pop-preview → `mountFormComponentAction('data.syncNowAction', 'syncNow')` (the component key is the schema state-path key — a bare `'syncNowAction'` resolves nothing and silently unmounts) → `$action->halt()` so Filament's tail unmount doesn't pop the freshly-mounted picker; `Forms\Components\Actions\Action` is NOT a subclass of `Filament\Actions\Action` — halt closure params must type-hint the FORMS one). Test-call mechanics: `callFormComponentAction` mounts internally — calling `mountFormComponentAction` first double-mounts. Only the 2 env failures fired.
- 1020 / 3,687 — **two-step full-sync flow (owner 2026-10-30, Indonesian spec: "flow UI/UX full sync masih kurang pas… untuk fitur dry run dihilangkan saja") — net −2 tests/−10 assertions, a DELIBERATE FEATURE DELETION.** The Sync-now modal is now a two-step wizard on ONE form-component action: step 1 = the full-sync checkbox with a submit labelled "Sync" — it reads "Full sync" once the box is ticked; clicking it (ticked) drops the options memo, sets `NotionSettings::$fullSyncPickerOpen = true` and `halt()`s — the SAME modal then renders the restore picker (Select All kept); step 2's submit is "Import", DISABLED while the selection is empty (so the UI can no longer produce the `[]` restore-nothing pick — the service keeps the semantics for the console). Cancel + the X close both steps. **Deleted with the dry run:** the Preview button, `NotionSyncAction::previewModalContent()`, view `filament.actions.notion-dry-run`, `NotionSettings::$fullSyncPrefill`, and their tests (`test_the_preview_button_mounts_a_modal_with_the_dry_run_results`, `test_continuing_from_the_preview_opens_the_full_sync_picker_pre_ticked`, `test_the_preview_modal_content_is_the_read_only_full_dry_run`, `test_the_preview_modal_marks_failed_rows`, `test_the_shared_sync_modal_carries_the_full_sync_checkbox_and_warning`, `test_sync_now_full_sync_with_an_empty_pick_restores_nothing` — replaced by the disabled-Import test); the SERVICE-level dry run (the `dryRun:` param, paginated totals, itemized findings) is NOT deleted — console `notion:sync-issues --dry-run` keeps it, and a real sync's failure lines still surface in the failure toast. Mechanics learned this round: `blank(false)`/`filled(false)` are FALSE/TRUE — booleans are never blank, so checkbox reads must be `(bool) ($data[...] ?? false)`, never `blank()`/`filled()` (the first cut silently fell through to the full-sync branch and halted EVERY submit — the tell was "still mounted, no toast, mock called 0 times"); a `modalSubmitAction`/`modalSubmitActionLabel` closure resolves its params by NAME and `getFormData()` is only hydrated on CALL, never on render — read the modal form's live state via `$livewire->mountedFormComponentActionsData[0]`, and name the modalSubmitAction param exactly `$action`, because any other name resolves a throwaway `StaticAction` via `app()->make()` and the `->disabled()` lands on nothing. Only the 2 env failures fired.
- 1020 / 3,688 — prod-fix round (owner 2026-10-30, "ketika sudah memilih issue tombol Import tidak aktif") +1 assertion: the restore picker's CheckboxList was NOT `live()` — ticks (and Select All, which just fires `change` events per checkbox) updated `mountedFormComponentActionsData` but never re-rendered the modal, so the footer's Import button kept its render-time disabled state and stayed grey forever. The feature tests missed it because they re-resolve the action server-side after `set()` — that re-evaluates the disabled closure directly, bypassing the render path the browser actually uses. Now `->live()` re-renders on every tick and the disabled closure re-evaluates; `test_the_sync_modal_carries_the_full_sync_checkbox_and_the_restore_picker` pins `isLive()`. Only the 2 env failures fired.
