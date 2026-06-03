# War Room / Discussion Forum — Improvement Roadmap

**Created:** 2026-05-26
**Status:** Planning
**Estimated Total Effort:** 15-19 weeks across 5 phases

---

## Overview

This document outlines a phased improvement plan for the War Room (Discussion Forum) feature. The War Room is a multi-agent AI analysis system with 15 specialist agents, multi-round debate, function-calling tools, real-time streaming, and PDF export.

### Current State

| Metric | Value |
|--------|-------|
| Backend services | 6 files, ~2,686 lines |
| Frontend (single blade file) | 3,485 lines (Alpine.js + HTML + CSS) |
| Controllers | 11 invokable controllers |
| Test coverage | 0% |
| Agents | 15 configurable specialist agents |
| Tools | 6 function-calling tools |
| Broadcast events | 4 WebSocket events |

### Key Files

| File | Lines | Role |
|------|-------|------|
| `resources/views/filament/pages/war-room.blade.php` | 3,485 | Entire frontend (monolithic) |
| `app/Services/WarRoom/WarRoomService.php` | 1,014 | Core orchestrator |
| `app/Services/WarRoom/AgentPromptBuilder.php` | 784 | Prompt engineering |
| `app/Services/WarRoom/WarRoomStreamingService.php` | 334 | SSE streaming |
| `app/Services/WarRoom/WarRoomToolRegistry.php` | 196 | Tool definitions |
| `app/Services/WarRoom/WarRoomToolExecutor.php` | 232 | Tool execution |
| `app/Services/WarRoom/AgentSuggestionService.php` | 126 | AI agent selection |
| `app/Models/WarRoomSession.php` | 172 | Session model |
| `app/Models/WarRoomMessage.php` | 81 | Message model |
| `app/Models/WarRoomAgentConfig.php` | 59 | Agent config model |
| `config/ai.php` (war_room section) | ~35 | Configuration |

---

## Phase 1: Foundation & Stability

**Priority:** Highest
**Effort:** Large (3-4 weeks)
**Goal:** Test coverage, fix reactivity bugs, add cost controls, clean up routing

This phase must complete before any frontend refactoring or new feature work begins.

---

### Task 1.1: Create Model Factories and Test Base Classes

**Description:** Create test infrastructure for all subsequent test tasks. No War Room factories exist yet.

**Files to create:**
- `database/factories/WarRoomSessionFactory.php`
- `database/factories/WarRoomMessageFactory.php`
- `database/factories/WarRoomAgentConfigFactory.php`

**Implementation:**

`WarRoomSessionFactory`:
- `user_id` => `UserFactory::new()`
- `incident_id` => `IncidentFactory::new()`
- `title` => `fake()->sentence()`
- `status` => `'pending'`
- `current_round` => 0, `max_rounds` => 2
- `model` => `config('ai.war_room.default_model', 'SMART-MODEL')`
- `selected_agents` => `['sre', 'tech_risk', 'dba']`
- `incident_context` => `['Sample incident context data']`
- `tokens_used` => 0
- State methods: `running()` (status='running', started_at=now()), `completed()` (status='completed', completed_at=now(), final_report + html)

`WarRoomMessageFactory`:
- `session_id` => `WarRoomSessionFactory::new()`
- `round` => 1, `agent_role` => `'sre'`, `role` => `'assistant'`, `status` => `'pending'`
- State methods: `running()`, `completed()` (populates content, tokens, response_time)

`WarRoomAgentConfigFactory`:
- `role_key` => `fake()->unique()->word()`, `display_name`, `description`, `skills`, `icon`, `color`, `system_prompt`, `is_active` => true

**Acceptance criteria:**
- All three factories instantiate and persist in test SQLite database
- State methods work correctly
- `WarRoomAgentConfig` unique by `role_key`

**Dependencies:** None

---

### Task 1.2: Unit Tests for WarRoomToolExecutor

**Description:** Test all 6 tool execution methods in isolation.

**Files to create:**
- `tests/Unit/Services/WarRoom/WarRoomToolExecutorTest.php`

**Test cases (15):**
1. `test_search_incidents_with_severity_filter` — create 3 incidents (P1, P2, P3), search for P1, assert only P1
2. `test_search_incidents_with_text_query` — search by keyword in title
3. `test_search_incidents_returns_empty_when_no_results`
4. `test_get_incident_details_returns_formatted_data`
5. `test_get_incident_details_returns_not_found`
6. `test_find_similar_incidents` — mock `RecurrenceDetectionService`
7. `test_find_similar_incidents_no_match`
8. `test_get_action_items_returns_pending_and_done`
9. `test_get_action_items_filters_by_status`
10. `test_web_search_delegates_to_service` — mock `WebSearchService`
11. `test_web_search_empty_query`
12. `test_get_stats_returns_yearly_stats` — use `Cache::fake()`
13. `test_execute_returns_tool_result_format` — verify `['role' => 'tool', 'tool_call_id' => ..., 'content' => ...]`
14. `test_execute_handles_unknown_tool`
15. `test_execute_catches_exceptions_gracefully`

**Acceptance criteria:**
- All 15 tests pass
- No actual HTTP calls (all external dependencies mocked)
- Test runs in under 2 seconds

**Dependencies:** Task 1.1

---

### Task 1.3: Unit Tests for WarRoomToolRegistry

**Description:** Test tool definition generation and filtering.

**Files to create:**
- `tests/Unit/Services/WarRoom/WarRoomToolRegistryTest.php`

**Test cases (9):**
1. `test_get_tool_definitions_returns_all_6_tools`
2. `test_get_tool_definitions_filters_by_enabled_tools` — pass subset, assert only those returned
3. `test_get_tool_definitions_returns_all_when_null`
4. `test_get_tool_definitions_empty_array_returns_empty`
5. `test_get_all_tool_names_returns_expected_list`
6. `test_search_incidents_has_correct_parameters`
7. `test_get_incident_details_has_required_parameter`
8. `test_web_search_has_required_parameter`
9. `test_get_stats_has_period_enum`

**Acceptance criteria:** All 9 tests pass, no DB/HTTP dependencies

**Dependencies:** None

---

### Task 1.4: Unit Tests for AgentPromptBuilder

**Description:** Test prompt construction logic for agents and moderator.

**Files to create:**
- `tests/Unit/Services/WarRoom/AgentPromptBuilderTest.php`

**Test cases (14):**
1. `test_build_agent_prompt_includes_base_prompt`
2. `test_build_agent_prompt_includes_incident_context`
3. `test_build_agent_prompt_includes_user_instructions`
4. `test_build_agent_prompt_includes_cross_incident_for_multi`
5. `test_build_agent_prompt_includes_skills_list`
6. `test_build_round_user_message_round_1_single`
7. `test_build_round_user_message_round_1_multi_incident`
8. `test_build_round_user_message_round_2`
9. `test_build_previous_round_summary_extracts_key_findings`
10. `test_build_previous_round_summary_falls_back_to_tail_section`
11. `test_build_moderator_user_message_includes_all_rounds`
12. `test_build_moderator_user_message_notes_partial_data`
13. `test_get_default_agents_returns_15_agents`
14. `test_get_default_agents_structure`

Mock `SkillPromptBuilder` and `PromptOptimizer`.

**Acceptance criteria:** All 14 tests pass, no HTTP calls

**Dependencies:** Task 1.1

---

### Task 1.5: Unit Tests for WarRoomService Core Methods

**Description:** Test the orchestrator's session management, round dispatch, and completion logic.

**Files to create:**
- `tests/Unit/Services/WarRoom/WarRoomServiceTest.php`

**Test cases (15):**
1. `test_create_session_creates_session_and_dispatches_job`
2. `test_create_session_throws_for_empty_incidents`
3. `test_start_session_marks_running_and_dispatches_round`
4. `test_dispatch_round_creates_messages_for_each_agent`
5. `test_on_agent_completed_advances_round_when_all_done`
6. `test_on_agent_completed_synthesizes_report_on_final_round`
7. `test_on_agent_completed_marks_failed_when_all_agents_fail`
8. `test_on_agent_completed_does_nothing_with_pending_agents`
9. `test_reanalyze_session_resets_and_redispatches`
10. `test_reanalyze_session_throws_for_running_session`
11. `test_retry_failed_agent_resets_message_and_dispatches`
12. `test_retry_report_synthesis_only_for_failed_session`
13. `test_mark_stuck_messages_marks_timed_out_running`
14. `test_mark_stuck_messages_redispatches_stuck_pending`
15. `test_get_session_data_returns_expected_structure`

Use `Queue::fake()` and `Event::fake()`.

**Acceptance criteria:** All 15 tests pass, no actual queue/broadcasting

**Dependencies:** Task 1.1

---

### Task 1.6: Feature Tests for War Room Controllers

**Description:** Integration tests for all 11 controller endpoints.

**Files to create:**
- `tests/Feature/WarRoom/WarRoomCreateControllerTest.php`
- `tests/Feature/WarRoom/WarRoomRetryControllerTest.php`
- `tests/Feature/WarRoom/WarRoomShowControllerTest.php`
- `tests/Feature/WarRoom/WarRoomListControllerTest.php`
- `tests/Feature/WarRoom/WarRoomDeleteControllerTest.php`
- `tests/Feature/WarRoom/WarRoomPollControllerTest.php`
- `tests/Feature/WarRoom/WarRoomReanalyzeControllerTest.php`
- `tests/Feature/WarRoom/WarRoomEstimateTokensControllerTest.php`
- `tests/Feature/WarRoom/WarRoomAvailableAgentsControllerTest.php`

**Key test cases per controller:**

`WarRoomCreateControllerTest`: create success, 409 conflict for existing active, validation (required fields, incident_ids, max_agents), unauthorized user

`WarRoomRetryControllerTest`: retry all failed, retry no failed, retry single agent, retry report only, retry report blocked when agents failed, regenerate report, unauthorized

`WarRoomShowControllerTest`: show returns data, show only own sessions

`WarRoomDeleteControllerTest`: delete success, delete only own sessions

**Acceptance criteria:** All endpoints tested, auth enforced, validation tested, response formats verified

**Dependencies:** Task 1.1

---

### Task 1.7: Fix Alpine.js Reactivity for Message Properties

**Description:** Properties added to message objects after Alpine initialization (`_expanded`, `_showThinking`, `_showTools`, `_streaming`) are not reactive. This caused the "invisible streaming content" bug and risks future reactivity failures.

**Files to modify:**
- `resources/views/filament/pages/war-room.blade.php` (lines ~379-381, ~505, ~426-439)

**Implementation:**

Add a `normalizeMessages()` method that recreates message objects with all properties pre-defined:

```javascript
normalizeMessages(session) {
    if (session.messages) {
        const normalized = {};
        for (const [round, msgs] of Object.entries(session.messages)) {
            normalized[round] = msgs.map(m => ({
                ...m,
                _expanded: false,
                _showThinking: false,
                _showTools: false,
                _streaming: false,
            }));
        }
        session.messages = normalized;
    }
    return session;
}
```

Replace all inline `forEach` property assignments with `this.normalizeMessages()`. Also fix `onAgentStreaming` handler where `msg._streaming = true` and `msg.content += e.delta` mutate non-reactively.

**Acceptance criteria:**
- Expand/collapse always works on first click
- Thinking/tools panels toggle reliably
- Streaming content visible in real-time
- No stale state after WebSocket events

**Dependencies:** None

---

### Task 1.8: Add Cost Controls (Per-User Session Limits & Token Budgets)

**Description:** No limits on sessions or token consumption. Add configurable rate limits and daily token budgets.

**Files to modify:**
- `config/ai.php` — add rate limits config
- `app/Services/WarRoom/WarRoomService.php` — add `enforceBudgetLimits($user)` in `createSession()`
- `app/Http/Controllers/Ai/WarRoomCreateController.php` — return 429 on budget exceeded

**Config to add:**
```php
'rate_limits' => [
    'max_sessions_per_user_per_day' => (int) env('AI_WAR_ROOM_MAX_DAILY_SESSIONS', 10),
    'max_active_sessions_per_user' => (int) env('AI_WAR_ROOM_MAX_ACTIVE_SESSIONS', 3),
    'max_daily_tokens_per_user' => (int) env('AI_WAR_ROOM_MAX_DAILY_TOKENS', 500000),
    'max_total_tokens_per_session' => (int) env('AI_WAR_ROOM_MAX_SESSION_TOKENS', 200000),
],
```

**Budget enforcement in `createSession()`:**
- Count today's sessions → reject if exceeds daily max
- Count active sessions → reject if exceeds concurrent max
- Sum today's tokens → reject if exceeds daily budget

**In-session enforcement in `processAgent()`:**
- After `$session->addTokens()`, check if session budget exceeded
- If so, fail remaining agents and session

**Acceptance criteria:**
- All limits enforced, configurable via `.env`
- Clear 429 error messages
- Session auto-stops when token budget exceeded

**Dependencies:** None

---

### Task 1.9: Refactor Inline Incident Search Route to Controller

**Description:** The incident search route in `routes/web.php` (lines ~200-225) uses an inline closure, which can't be cached in production.

**Files to create:**
- `app/Http/Controllers/Ai/WarRoomIncidentSearchController.php`

**Files to modify:**
- `routes/web.php` — replace closure with controller reference

Move the exact closure logic into `WarRoomIncidentSearchController::__invoke()`. Validate `q` (min 2 chars), query incidents by `no`/`title`/`summary`, eager-load `pic`, limit 10.

**Acceptance criteria:** Search works identically, route list shows controller class, testable in isolation

**Dependencies:** None

---

### Task 1.10: Add War Room Job Tests

**Description:** Test the three queue jobs handle success and failure correctly.

**Files to create:**
- `tests/Unit/Jobs/WarRoom/StartWarRoomSessionJobTest.php`
- `tests/Unit/Jobs/WarRoom/ProcessWarRoomAgentJobTest.php`
- `tests/Unit/Jobs/WarRoom/SynthesizeWarRoomReportJobTest.php`

**Test cases:**

`StartWarRoomSessionJobTest`: handle calls startSession, failed marks session failed

`ProcessWarRoomAgentJobTest`: handle calls processAgent, failed marks message failed, auto-retry on ConnectionException, no retry beyond max, skips completed messages

`SynthesizeWarRoomReportJobTest`: handle calls synthesizeReport, failed marks session failed

**Acceptance criteria:** All job tests pass, Queue::fake() used, auto-retry logic covered

**Dependencies:** Task 1.1

---

### Phase 1 Completion Criteria

- [ ] 70+ test cases passing
- [ ] 60%+ line coverage for War Room services
- [ ] Alpine.js reactivity fix verified in browser
- [ ] Cost controls enforced on session creation
- [ ] No route closures remain in `routes/web.php`
- [ ] All config values have `.env` overrides

---

## Phase 2: Frontend Modernization

**Priority:** High
**Effort:** Large (3-4 weeks)
**Goal:** Break the monolithic 3,485-line blade file into maintainable pieces, add UX improvements

---

### Task 2.1: Extract CSS to External Stylesheet

**Description:** ~2,100 lines of CSS are inline in the blade file. Extract to an external file.

**Files to create:**
- `public/css/war-room.css`

**Files to modify:**
- `resources/views/filament/pages/war-room.blade.php` — replace `<style>` block with `@push('styles')` + `<link>`

**Implementation:**
1. Extract all CSS between `<style>` and `</style>` (lines ~2700-3485)
2. Replace with `@push('styles') <link rel="stylesheet" href="{{ asset('css/war-room.css') }}"> @endpush`
3. Verify dark mode (`:root.dark` selectors) still works — class is on `<html>`, so external CSS works fine
4. Verify CSS custom properties (`--df-*`) referencing Filament variables work globally

**Acceptance criteria:**
- All UI renders identically
- No inline `<style>` block remains
- Dark mode works
- No specificity conflicts with Filament

**Dependencies:** Task 1.7 (reactivity fix first since both touch the blade file)

---

### Task 2.2: Extract Alpine.js to Separate JS Modules

**Description:** ~700 lines of JavaScript in a single Alpine data object. Extract to modular files.

**Files to create:**
- `public/js/war-room-utils.js` — `colorMap`, `hexToRgba`, `formatDate`, `toolLabel` → `window.WarRoomUtils`
- `public/js/war-room-agents.js` — agent cache, reference processing, content rendering → `window.WarRoomAgents`
- `public/js/war-room-session.js` — polling, WebSocket handlers, state management → `window.WarRoomSession`
- `public/js/war-room-form.js` — incident search, agent selection, token estimation → `window.WarRoomForm`

**Files to modify:**
- `resources/views/filament/pages/war-room.blade.php` — reference external JS, keep only Alpine data init inline

**Structure:**
```javascript
Alpine.data('warRoom', () => ({
    ...window.WarRoomSession.createInitialState(),
    async init() {
        window.WarRoomUtils.initMermaid();
        await this.loadAgents();
        await this.loadSessions();
    },
    // ... event handlers only
}));
```

**Acceptance criteria:**
- Blade JS section reduced from ~700 to ~200 lines
- Each external file under 200 lines
- All functionality identical
- No global namespace pollution (scoped under `window.WarRoom*`)

**Dependencies:** Task 1.7, Task 2.1

---

### Task 2.3: Add Round Progress & Agent Completion Indicators

**Description:** Users can't see overall progress during a running session. Add visual tracking.

**Files to modify:**
- `resources/views/filament/pages/war-room.blade.php` — add progress bar in header (~line 800)
- `public/css/war-room.css` — add progress bar styles

**Implementation:**

Add computed property `sessionProgress`:
```javascript
get sessionProgress() {
    if (!this.activeSession?.messages) return null;
    const all = Object.values(this.activeSession.messages).flat();
    const total = all.length;
    const completed = all.filter(m => m.status === 'completed').length;
    const failed = all.filter(m => m.status === 'failed').length;
    const running = all.filter(m => m.status === 'running').length;
    return { total, completed, failed, running, percentage: Math.round((completed / total) * 100) };
}
```

Add progress bar in header + per-round agent counts in round headers.

**Acceptance criteria:**
- Progress bar visible during active sessions
- Updates in real-time as agents complete
- Per-round completion counts visible
- Failed agents shown distinctly

**Dependencies:** Task 1.7 (reactivity ensures progress updates work)

---

### Task 2.4: Convert Moderator Report to Streaming

**Description:** Report synthesis uses non-streaming HTTP — users wait 30-60+ seconds with no feedback. Convert to streaming.

**Files to create:**
- `app/Events/WarRoomReportStreaming.php` — new broadcast event

**Files to modify:**
- `app/Services/WarRoom/WarRoomService.php` — rewrite `synthesizeReport()` to use `WarRoomStreamingService`
- `resources/views/filament/pages/war-room.blade.php` — handle `report.streaming` WebSocket event

**Implementation:**

1. Create `WarRoomReportStreaming` event (broadcast as `report.streaming`, uses `ShouldBroadcastNow`)
2. Rewrite `synthesizeReport()` to use `$this->streamingService->streamCompletion()` with throttled callbacks (same pattern as `processAgent()`)
3. Add `.report.streaming` listener in Echo that appends delta to `final_report_html`

**Acceptance criteria:**
- Report text appears incrementally
- No 30-60s dead wait
- Continuation logic preserved
- Final report saved to DB correctly

**Dependencies:** Task 1.7, Task 2.2

---

### Task 2.5: Session Templates (Save Agent Presets)

**Description:** Allow saving agent selections and settings as reusable templates.

**Files to create:**
- `database/migrations/*_create_war_room_templates_table.php`
- `app/Models/WarRoomTemplate.php`
- `app/Http/Controllers/Ai/WarRoomTemplateController.php`

**Files to modify:**
- `routes/web.php` — add template CRUD routes
- `resources/views/filament/pages/war-room.blade.php` — template save/load UI

**Migration:** `id` (uuid), `user_id`, `name`, `selected_agents` (json), `max_rounds`, `model`, `moderator_model`, `enable_web_search`, `deep_analysis`, `user_instructions`, timestamps

**Controller:** `index()`, `store()`, `update()`, `destroy()` — scoped to user

**UI:** "Save as Template" button in Settings tab, template selector dropdown above agent selection

**Acceptance criteria:**
- Save/load/delete templates
- Scoped to user
- Template list loads < 200ms

**Dependencies:** None

---

### Phase 2 Completion Criteria

- [ ] Blade file reduced from 3,485 to ~1,800 lines
- [ ] CSS in external file, no visual regressions
- [ ] JS modularized into 4 files
- [ ] Progress indicators work during live sessions
- [ ] Moderator report streams in real-time
- [ ] Session templates saveable/loadable
- [ ] All Phase 1 tests still pass

---

## Phase 3: Collaboration & UX

**Priority:** Medium
**Effort:** Large (3-4 weeks)
**Goal:** Multi-user collaboration, notifications, interactivity, export formats

---

### Task 3.1: Session Sharing (Observer Mode)

**Description:** Allow multiple users to observe a running session in real-time.

**Files to create:**
- `database/migrations/*_create_war_room_session_viewers_table.php`
- `app/Models/WarRoomSessionViewer.php`
- `app/Http/Controllers/Ai/WarRoomShareController.php`

**Files to modify:**
- `app/Models/WarRoomSession.php` — add `viewers()`, `addViewer()`, `isAccessibleBy()`
- All controllers using `forUser()` — update to check ownership OR viewer status
- `routes/channels.php` — update WebSocket channel auth for viewers
- `resources/views/filament/pages/war-room.blade.php` — share button + viewer management

**Migration:** `war_room_session_viewers` — `id` (uuid), `session_id`, `user_id`, `role` (enum: 'viewer'), unique constraint on `(session_id, user_id)`

**Access model:** Viewers have read-only access (no delete/retry/re-analyze). WebSocket channel auth updated to include viewers.

**Acceptance criteria:**
- Share/unshare via user search
- Shared user sees session in their sidebar
- Real-time WebSocket works for viewers
- Read-only enforcement

**Dependencies:** Phase 1, Phase 2

---

### Task 3.2: Browser Notifications for Session Completion

**Description:** Notify users when long-running sessions complete, even if they navigated away.

**Files to modify:**
- `resources/views/filament/pages/war-room.blade.php` — Notification API request
- `public/js/war-room-session.js` — notification logic

**Implementation:**
- Request permission on session start (not on page load)
- Fire `new Notification()` in `onSessionCompleted()` when tab not focused
- Include session title, icon, and click-to-focus behavior

**Acceptance criteria:** Notification appears when session completes and tab is not focused; works in Chrome/Firefox/Safari

**Dependencies:** Task 2.2

---

### Task 3.3: Agent Interactivity (Inject Questions Mid-Session)

**Description:** Allow users to inject questions/instructions for agents during a running session.

**Files to create:**
- `database/migrations/*_add_user_messages_support_to_war_room.php`
- `app/Http/Controllers/Ai/WarRoomInjectQuestionController.php`

**Files to modify:**
- `app/Models/WarRoomMessage.php` — support `role => 'user'` messages
- `app/Services/WarRoom/WarRoomService.php` — add `injectQuestion()` method
- `app/Services/WarRoom/AgentPromptBuilder.php` — include user messages in round context
- `resources/views/filament/pages/war-room.blade.php` — question input UI
- `routes/web.php` — inject route

**Migration:** Add `parent_message_id` (nullable uuid) and `visibility` (enum: 'all', 'agent') to `war_room_messages`

**Flow:** Create `WarRoomMessage` with `role='user'` → include in next round's prompt → show as distinct card in timeline

**Acceptance criteria:**
- Submit question during running session
- Question persisted and included in next round prompt
- Visually distinct in timeline
- Only session owner can inject

**Dependencies:** Phase 1

---

### Task 3.4: Additional Export Formats (Markdown & JSON)

**Description:** Add Markdown and JSON export alongside existing PDF.

**Files to create:**
- `app/Http/Controllers/Ai/WarRoomExportMarkdownController.php`
- `app/Http/Controllers/Ai/WarRoomExportJsonController.php`

**Files to modify:**
- `routes/web.php` — add export routes
- `resources/views/filament/pages/war-room.blade.php` — add options in dropdown menu

**Markdown export:** Title, date, incidents, rounds with agent headers, final report → `Content-Type: text/markdown`

**JSON export:** Full `getSessionData()` structure as downloadable JSON

**Acceptance criteria:** Both formats download correctly, only for completed sessions, respects auth

**Dependencies:** None

---

### Task 3.5: Comparison View (Side-by-Side Session Comparison)

**Description:** Compare two sessions side-by-side (useful for re-analyses with different models).

**Files to modify:**
- `resources/views/filament/pages/war-room.blade.php` — comparison mode UI
- `public/css/war-room.css` — comparison layout styles

**Implementation:**
- Checkbox mode in sidebar to select 2 sessions
- Split main content into two columns
- Toggle between: final reports comparison / agent-by-agent alignment
- Visual diff highlighting

**Acceptance criteria:** Select 2 sessions, view side-by-side, exit comparison mode cleanly

**Dependencies:** Task 2.1, Task 2.2

---

### Phase 3 Completion Criteria

- [ ] Sessions shareable with other users
- [ ] Browser notifications on completion
- [ ] Questions injectable mid-session
- [ ] Markdown + JSON export available
- [ ] Side-by-side comparison works
- [ ] All Phase 1-2 features functional

---

## Phase 4: Intelligence & Optimization

**Priority:** Medium
**Effort:** Large (3-4 weeks)
**Goal:** Agent learning, smart context management, performance optimization, monitoring

---

### Task 4.1: Agent Memory & Adaptive Prompts

**Description:** Track retry patterns and prompt adjustments, auto-adjust based on historical performance.

**Files to create:**
- `database/migrations/*_create_war_room_agent_performance_table.php`
- `app/Services/WarRoom/AgentPerformanceService.php`

**Files to modify:**
- `app/Services/WarRoom/WarRoomService.php` — record metrics after agent completes/fails
- `app/Services/WarRoom/AgentPromptBuilder.php` — performance-based prompt adjustments

**Migration:** `war_room_agent_performance` — `agent_role`, `model`, `success`, `retry_count`, `response_time_ms`, `total_tokens`, `content_length`, `finish_reason`, `error_message`, `round_number`, `session_id`, `created_at`

**Service methods:** `recordPerformance()`, `getAverageResponseTime()`, `getSuccessRate()`, `getOptimalModel()`, `getRetryPatterns()`, `getRecommendedPromptAdjustments()`

**Adaptive prompts:** High retry rate → add minimum word count instruction; frequent timeouts → add conciseness instruction

**Acceptance criteria:** Performance recorded for every run, cached historical queries, prompt adjustments applied when enabled

**Dependencies:** Phase 1

---

### Task 4.2: Smart Context Management (Adaptive Compression)

**Description:** Replace simple 3-level compression with adaptive strategy based on model, round, and budget.

**Files to modify:**
- `app/Services/WarRoom/WarRoomService.php` — enhance `compressContext()`
- `config/ai.php` — add compression config

**Config:**
```php
'compression' => [
    'strategy' => env('AI_WAR_ROOM_COMPRESSION', 'adaptive'),
    'target_utilization' => 0.70,
    'round_2_context_ratio' => 0.5,
],
```

**Adaptive logic:**
- Round 2+: compress more aggressively (summary + root cause + key metrics only)
- Large context models (>100k): skip compression
- Small context models (<32k): use minimal from start
- Store compression level in session metadata

**Acceptance criteria:** Round 2 uses less context, large models skip compression, compression level logged

**Dependencies:** Task 4.1

---

### Task 4.3: Performance Optimization (Caching & Batch Queries)

**Description:** Optimize database queries and add caching for frequently accessed data.

**Files to modify:**
- `app/Services/WarRoom/WarRoomToolExecutor.php` — session-scoped tool result caching (5min)
- `app/Models/WarRoomAgentConfig.php` — increase cache TTL from 5 to 30 min
- `app/Services/WarRoom/WarRoomService.php` — eager loading, batch queries in `onAgentCompleted()`
- `app/Http/Controllers/Ai/WarRoomPollController.php` — 5s response cache

**Optimizations:**
- Tool results cached per session: `warroom:tool:{sessionId}:{toolName}:{args_hash}`
- Agent config cache TTL: 30 minutes
- `getSessionData()`: eager load messages, user, incidents.pic, incidents.labels
- `onAgentCompleted()`: single `selectRaw('status, count(*)')` grouped query
- Poll cache: 5s TTL, invalidate on WebSocket event

**Acceptance criteria:** Poll response time reduced 50%+, no N+1 queries, cache hit rate > 95%

**Dependencies:** Phase 1

---

### Task 4.4: Monitoring Dashboard (Token Usage & Agent Metrics)

**Description:** Admin dashboard showing War Room usage metrics, costs, and agent performance.

**Files to create:**
- `app/Filament/Pages/WarRoomMetrics.php` — new Filament page
- `app/Http/Controllers/Ai/WarRoomMetricsController.php`

**Files to modify:**
- `routes/web.php` — metrics route

**Endpoints:**
- `GET /metrics/overview` — sessions, tokens, duration, success/failure ratio, top users (cached 5min)
- `GET /metrics/agents` — per-agent response time, success rate, token usage
- `GET /metrics/models` — per-model performance comparison

**UI:** Token usage chart (daily, 30 days), agent success rate table, model comparison cards, cost estimation

**Acceptance criteria:** Metrics page loads < 1s, data accurate, charts render, admin-only access

**Dependencies:** Task 4.1

---

### Phase 4 Completion Criteria

- [ ] Agent performance data collected and actionable
- [ ] Context compression adaptive and logged
- [ ] Polling and session loading optimized
- [ ] Admin metrics dashboard functional
- [ ] No regression in Phase 1-3

---

## Phase 5: Advanced Features

**Priority:** Low (stretch goals)
**Effort:** Large (4-5 weeks)
**Goal:** Custom agents, public API, auto-trigger, historical analysis

---

### Task 5.1: Custom Agent Builder

**Description:** Allow admins to create custom agents with their own system prompts, skills, and tools via UI.

**Files to modify:**
- `app/Filament/Resources/WarRoomAgentConfigResource.php` — enhance existing CRUD with "Test Agent" action, "Enhance Prompt" and "Suggest Skills" buttons

**Features:**
- Rich form with system prompt editor, skill/tag input, tool multi-select, model override
- "Test Agent" creates a single-agent, single-round session to validate prompt
- Built-in agents protected from deletion

**Acceptance criteria:** Admin creates agent → appears in selection list → works correctly in sessions

**Dependencies:** Phase 2, Phase 4

---

### Task 5.2: War Room Public API

**Description:** Expose War Room functionality via API for external integrations.

**Files to create:**
- `routes/api.php` — API route group
- `app/Http/Controllers/Api/WarRoomApiController.php`

**Endpoints:** `POST /sessions`, `GET /sessions`, `GET /sessions/{id}`, `GET /sessions/{id}/status`, `GET /sessions/{id}/report`, `DELETE /sessions/{id}`

**Auth:** Laravel Sanctum API tokens, `throttle:30,1` rate limiting, JSON:API response format, Scribe auto-docs

**Acceptance criteria:** External clients can create/monitor sessions via API, rate limited, documented

**Dependencies:** Phase 1, Phase 4

---

### Task 5.3: Incident Auto-Trigger

**Description:** Auto-start War Room when high-severity incidents are created or escalated.

**Files to create:**
- `app/Listeners/TriggerWarRoomForHighSeverity.php`

**Files to modify:**
- `app/Providers/EventServiceProvider.php` — register listener
- `config/ai.php` — auto-trigger config

**Config:**
```php
'auto_trigger' => [
    'enabled' => env('AI_WAR_ROOM_AUTO_TRIGGER', false),
    'severity_threshold' => ['P1', 'P2'],
    'default_agents' => ['sre', 'system', 'tech_risk', 'security'],
    'skip_if_existing_session' => true,
],
```

**Flow:** IncidentCreated/Escalated event → check severity → check no existing session → create with defaults → notify assigned user

**Acceptance criteria:** P1/P2 auto-triggers (when enabled), no duplicates, disabled by default

**Dependencies:** Phase 1, Phase 3

---

### Task 5.4: Historical Analysis (Trend Analysis Across Sessions)

**Description:** Analyze patterns across multiple sessions for the same incident.

**Files to create:**
- `app/Http/Controllers/Ai/WarRoomHistoricalAnalysisController.php`
- `app/Services/WarRoom/HistoricalAnalysisService.php`

**Service methods:** `getSessionsForIncident()`, `compareSessionsAcrossTime()`, `getRecurringFindings()`, `getAgentConsensus()`, `getRecommendationEvolution()`

**UI:** "Historical Analysis" tab for incidents with 2+ sessions, timeline of sessions, recurring findings highlighted

**Acceptance criteria:** View all sessions for an incident, AI meta-analysis, recurring findings highlighted

**Dependencies:** Phase 4

---

### Phase 5 Completion Criteria

- [ ] Custom agents creatable via admin UI
- [ ] Public API functional with documentation
- [ ] High-severity incidents auto-trigger (opt-in)
- [ ] Historical analysis across sessions works
- [ ] All previous phases functional with no regressions

---

## Dependency Graph

```
Phase 1 (Foundation)
  1.1 Factories ──────────> 1.2, 1.4, 1.5, 1.6, 1.10
  1.7 Reactivity fix ─────> 2.3, 2.4, 3.2
  1.8 Cost controls ──────> 5.3
  1.9 Route refactor ─────> (independent)

Phase 2 (Frontend)
  2.1 CSS extraction ─────> 2.2, 3.5
  2.2 JS extraction ──────> 2.4, 3.2, 3.5
  2.3 Progress indicators ─> (after 1.7)
  2.4 Report streaming ───> (after 1.7, 2.2)
  2.5 Templates ──────────> (independent)

Phase 3 (Collaboration)
  3.1 Session sharing ────> (after Phase 2)
  3.2 Notifications ──────> (after 2.2)
  3.3 Agent interactivity ─> (after Phase 1)
  3.4 Export formats ─────> (independent)
  3.5 Comparison view ────> (after 2.1, 2.2)

Phase 4 (Intelligence)
  4.1 Agent memory ───────> 4.2, 4.4
  4.2 Smart context ──────> (after 4.1)
  4.3 Performance opt ────> (after Phase 1)
  4.4 Metrics dashboard ──> (after 4.1)

Phase 5 (Advanced)
  5.1 Custom agents ──────> (after Phase 2, 4)
  5.2 Public API ─────────> (after Phase 1, 4)
  5.3 Auto-trigger ───────> (after Phase 1, 3)
  5.4 Historical analysis ─> (after Phase 4)
```

---

## Implementation Notes

### Before starting any task:
1. Ensure Phase 1 tasks are completed first (foundation)
2. Run `php artisan test` to verify no regressions
3. Run `./vendor/bin/pint` for code style
4. Test in browser for UI changes

### Per-task workflow:
1. Create feature branch: `feat/war-room-{task-number}`
2. Implement changes
3. Write/update tests
4. Run `php artisan test && ./vendor/bin/pint`
5. Manual browser testing for UI changes
6. PR with description referencing this document

### Configuration pattern:
All new config should:
- Go under `config/ai.php` in the `war_room` section
- Have sensible defaults
- Be overridable via `.env`
- Be documented in this file
