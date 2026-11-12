# dingtalk-agent

DingTalk **stream-mode** AI agent for the Technical Risk Dashboard. An outbound
WebSocket to DingTalk (works behind the VPN-only prod network — no public IP,
no callback URL). Team members @mention the bot (or DM it 1:1), it answers
from dashboard data via the Laravel v1 API, streaming the reply into a DingTalk
AI card (typewriter), falling back to a one-shot webhook reply when cards
aren't available.

**Brain:** fork of [metabbe3/GoAgent](https://github.com/metabbe3/GoAgent) (MIT,
see `LICENSE-GoAgent`). **Transport:** official
[open-dingtalk/dingtalk-stream-sdk-go](https://github.com/open-dingtalk/dingtalk-stream-sdk-go) v0.9.2-beta.1 (v0.9.1 has a `send on closed channel` panic that kills the process on network wobble — fixed in the beta's channel-lifecycle rewrite; VPN-only prod would hit it constantly).

## Fork surgery log

Upstream commit: `b71ae8ee0b3d0874f4e20bead1b30ad8a7793446` (MIT).

| Action | What |
|---|---|
| Kept (module path rewritten) | agent loop (8 iterations, 4-concurrent tool pool, 8 KB tool-result truncation, 60 K token pruning), guardrails (`secretredact`, `replyfilter`), OpenAI-compatible LLM client |
| Deleted | telegram package, logengine, commandguard, pathguard, all 8 local-machine tools, `AllowShell` plumbing |
| Added | `internal/dingtalk` (stream handler, @mention strip, allowlist, history+TTL, dedup, AI-card streaming, DM push), `internal/tools/dashboard.go` (3 read-only tools), env config, Dockerfile |

The reply guardrail runs on **every** streamed card frame and the final card;
the fallback webhook reply gets the same filter. Tool errors are returned to
the LLM as content (`Error executing …`) so it can self-correct — upstream
design, kept.

## Tools (all the LLM can reach — GET-only, read-only)

| Tool | Endpoint | Notes |
|---|---|---|
| `search_incidents` | `GET /incidents` | counts via `limit=1` + `meta.total`; single-sided dates auto-paired; limit capped at 100 |
| `get_incident` | `GET /incidents-by-no/{no}` or `/incidents/{id}` | path segment sanitized to `[A-Za-z0-9_-]` |
| `get_incident_markdown` | `GET /incidents-by-no/{no}/markdown` | base64-decoded for AI ingestion |
| `web_search` | DuckDuckGo HTML (keyless) | top 6 results, title + snippet + link |
| `web_fetch` | any public http(s) page | **SSRF-guarded** (dialer blocks loopback/private/link-local post-DNS), 256 KiB cap, tags stripped, ~7k chars |

API traps encoded in the tool layer (and tests): dates need **both** bounds or
the server ignores them; omitted `limit` = full-table dump; `search` needs ≥2
chars.

## Env contract

| Var | Required | Default / source |
|---|---|---|
| `DINGTALK_CLIENT_ID` / `DINGTALK_CLIENT_SECRET` | yes | DingTalk Open Platform app |
| `DINGTALK_ALLOWLIST` | no | **empty = everyone** (access gated by the DingTalk app's visibility range); comma staffIds to narrow further |
| `DASHBOARD_API_TOKEN` | yes | Filament → API Tokens; abilities `incidents`, `incidents-by-no`, `incidents-markdown`, `agent-schedules`, `agent-runs` |
| `DASHBOARD_API_BASE` | no | `http://nginx/api/v1` (compose) |
| `AGENT_INTERNAL_ADDR` | no | `:9091` — internal listener (the dashboard's Go engine POSTs `/internal/run` here); compose-network only |
| `AGENT_INTERNAL_TOKEN` | no | shared secret — **must equal** the dashboard `.env`'s `GO_AGENT_INTERNAL_TOKEN`; empty = listener disabled |
| `AGENT_TURN_REPORTS` | no | 1 — POST every chat turn (input/output/tokens/latency) to `/api/v1/agent-runs` under the synthetic "DingTalk Bot (chat turns)" agent; `0`/`off` silences it |
| `LLM_BASE_URL` | no | `AI_API_BASE_URL` → gateway default |
| `LLM_API_KEY` | no | `AI_API_KEY` (dashboard .env — compose maps it) |
| `LLM_MODEL` | no | `AI_API_DEFAULT_MODEL` (placeholder values ignored) → **`glm-5.3-flashx`** |
| `LLM_MAX_TOKENS` | no | `AI_WAR_ROOM_MAX_OUTPUT_TOKENS` → 16384; `0` omits `max_tokens` (escape hatch if a model rejects it) |
| `LLM_EMBEDDING_MODEL` | no | `AI_EMBEDDING_MODEL` → **`text-embedding-3-large`** — RAG knowledge-base embeddings (the key needs access to it) |
| `LLM_VISION_MODEL` | no | `AI_VISION_MODEL` → the chat model — image-reading model used to transcribe pictures sent to the bot (set it if the chat model isn't multimodal) |
| `AGENT_KB_TOOLS` | no | 1 — `search_knowledge`/`save_knowledge`/`list_knowledge`/`delete_knowledge` RAG over chat-uploaded documents and pictures; `0`/`off` unregisters the tools and the index |
| `AGENT_MAX_ITER` | no | 60 tool-call iterations (Hermes parity) |
| `AGENT_WEB_TOOLS` | no | 1 — keyless web research; `0`/`off`/`false`/`no` unregisters both web tools |
| `AGENT_NOTION_TOOLS` | no | 1 — read-only Notion doc tools (below); `0`/`off`/`false`/`no` unregisters all four |
| `NOTION_DATABASES` | no | `NAME=<id>` comma list (e.g. `PRD=<id>,TA=<id>`) seeding the agent's database catalog |
| `AGENT_AUTO_LEARN` | no | 1 — per-user preference learning: after each turn a background no-tools LLM call merges what the conversation revealed (language, format, standing instructions) into `data/profiles.json` (keyed by staffId, ≤8 lines × 150 chars, never wiped by an empty extraction); those lines are injected into that sender's system prompt only. Kill switch: `0`/`off` |
| `AGENT_MAX_REPLY_CHARS` | no | 8000 — lower it if DingTalk rejects large markdown replies |
| `DINGTALK_BOT_NAMES` | no | `Risk Agent` — strips whole-name @mentions in groups |
| `DINGTALK_DATA_DIR` | no | `data` — auto save/auto recall: who chatted (`users.jsonl`) + conversation history (`history.json`) survive restarts |
| `HISTORY_TTL` | no | 12h per-conversation memory (last 12 messages) |

Local dev: copy values into `dingtalk-agent.env` (git-ignored; loaded
automatically). Compose maps root `.env`'s `AI_API_*` → `LLM_*`.

**Known gap:** the Filament *AI Settings* page stores its values in the DB
(`AiSetting`); this agent reads **env only**. Changing the model for the bot
means changing `DINGTALK_MODEL`/env, not the AI Settings page.

## Owner runbook

1. **DingTalk app** (Open Platform → enterprise-internal app): note App
   Key/Secret → `DINGTALK_CLIENT_ID/SECRET`. Enable the Robot, message receive
   mode **Stream mode**, publish.
2. **Permissions** on the app: robot send-message (`qyapi_robot_sendmsg`) for
   proactive DMs; card streaming (`Card.Instance.Write` /
   `Card.Streaming.Write`) for typewriter replies — without card perms the bot
   still answers via webhook fallback.
3. **API token**: Filament → API Tokens → `dingtalk-agent` with the five
   abilities above → `DASHBOARD_API_TOKEN`.
   Then set the same shared secret in root `.env` as `DINGTALK_INTERNAL_TOKEN`
   **and** `GO_AGENT_INTERNAL_TOKEN`, and `GO_AGENT_BASE_URL=http://dingtalk-agent:9091`.
4. **Allowlist**: leave empty, have each user DM/mention the bot once, copy the
   `staffId` from the deny log (`denied sender staffId=…`), set
   `DINGTALK_ALLOWLIST`.
5. **Deploy**: `docker compose up -d --build dingtalk-agent`; logs should show
   the online line and stay connected (auto-reconnect is on).
6. **Test DM delivery**: `docker compose exec dingtalk-agent /agent -dm <staffId> -dm-text "hello"`
   (or `./agent -dm …` locally with `dingtalk-agent.env` present).

## Document ingestion (send the bot a file)

DM the bot a file (or send one in a group — must @mention it). Flow:
download via `POST /v1.0/robot/messageFiles/download` (needs the same
`qyapi_robot_sendmsg`-family app rights) → extract text → summarize /
follow the sender's caption instruction → **the reply's summary is saved to
`data/knowledge.jsonl`** and injected into every later system prompt (last 8
docs, summary clamped) → **the downloaded file is deleted from disk** in a
deferred remove, success or failure. Only summaries persist, never the file.

- Formats: txt, md, csv, json, log, xml (plain); docx (zip → document.xml →
  tag-strip); pdf (`ledongthuc/pdf`, pure Go — scanned/image PDFs yield "no
  extractable text").
- Caps: 15 MB download, 48,000 chars to the LLM, 2,000-char knowledge summary.
- Pictures/audio/video are not handled in v1 (they arrive as other msgtypes
  and are ignored).

### Knowledge base (RAG over uploaded documents)

Every ingested document is also embedded into a vector index — so SOP answers
come from the document text, not from a summary's memory of it. The
`search_knowledge` tool answers procedure/SOP questions by embedding the query
and returning the top-6 most similar passages (cosine), each labeled with its
document and part number; the agent is instructed to quote them.

- Index: `<DataDir>/kb.jsonl` (one JSON chunk per line). Chunks are 900 runes
  with 100 overlap, ≤150 chunks per document, ≤1000 chunks total (oldest
  document evicted whole at the cap). Brute-force in-memory scan — fine at
  this size.
- Embeddings: gateway `/v1/embeddings`, model from `LLM_EMBEDDING_MODEL` →
  `AI_EMBEDDING_MODEL` → **`text-embedding-3-large`** (same as the dashboard's
  `config/ai.php` default; the key must have access to it — the agent's local
  key does not). Array input is tried first, with automatic fallback to one
  request per chunk if the gateway rejects arrays.
- Re-embed: changing the model re-embeds every stored chunk in the background
  at boot (old vectors serve searches meanwhile).
- Failure mode: if embedding fails (no key access, gateway down), `Ingest`
  logs and the doc degrades to summary-only knowledge — uploads and answers
  never break.
- `AGENT_KB_TOOLS=0` turns the whole feature off (no index, no tool).

## Notion (read-only workspace docs)

Four tools proxy the Laravel v1 API's `/notion/*` endpoints, which reuse the
dashboard's Notion OAuth — the agent never sees a Notion token. All read-only
(POST requests, read semantics); `AGENT_NOTION_TOOLS=0` unregisters the set.

| Tool | Endpoint | Notes |
|---|---|---|
| `notion_search(query)` | `POST /notion/search` | workspace keyword search → titles + URLs |
| `notion_query_database(database, query)` | `POST /notion/query` | SQL against a NAMED database (or raw id/URL); rows capped at 100 by Notion — page with `WHERE createdTime >= '<last row createdTime>'` |
| `notion_read_page(id_or_url)` | `POST /notion/fetch` | one page as Markdown; per-tool result cap 48k (others stay 8k) |
| `notion_add_database(name, id)` | — | teaches the catalog a name; re-registers the query tool with the updated listing |

**Catalog semantics:** named databases live in `<DataDir>/notion_catalog.json`
(`{"databases": {name: {id, added_at}}}`). Seeds from `NOTION_DATABASES` are
**upsert-only** — env config never deletes a taught name; `notion_add_database`
adds/updates and persists, surviving restarts. Names match `^[A-Za-z0-9_-]{1,32}$`.
An unknown name-like reference errors with the current catalog listing (no
server call) so the LLM self-corrects. A raw database id/URL is **auto-saved**
as `db-<first 8 hex>` after its first *successful* query (failed queries save
nothing; an already-cataloged id is not duplicated); naming it later via
`notion_add_database` replaces the `db-…` entry.

**Knowledge append:** every `notion_read_page` records a read (title, URL,
3000-char excerpt); after the turn a background no-tools LLM call summarizes
the pages (≤6) into `data/knowledge.jsonl` as `Notion: <first title>` — the
same last-8 memory injected document uploads use.

**Token:** the `DASHBOARD_API_TOKEN` needs the `notion` endpoint ability ticked
in Filament → API Tokens (or an empty endpoint allowlist). A 403 with a
"missing ability" message means the token, not the agent.

## Hard guardrails (owner directive 2026-11-10 — no kill switch, by design)

The bot answers **only** about incidents/issues on the Technical Risk
dashboard, incident-related web research, and technical documents in the
connected Notion workspace, its identity is fixed as **Risk Agent**, and it
never writes poems/stories/essays/code/recipes or general-purpose content. Enforcement is
three Go layers — model compliance is not required for any of them:

1. **Input gate** (`guardrail.InputGate`, `stream.go` Handle): prompt-injection
   ("ignore/disregard/forget … instructions/aturan", "reveal your system
   prompt", "you are now a…", jailbreak/DAN/dev-mode, fake `system:` prefixes)
   and blatant off-topic asks (verb+object pairs, EN+ID: poem/puisi/cerita/
   story/essay/song/lagu/resep/recipe + language-tagged codegen) are refused
   with a **fixed** message before any LLM call — no token spend, no history
   pollution. File captions are gated the same way.
2. **LLM-context hardening** (`agent/loop.go`): every user message is wrapped
   in `[user message — …]` data markers (`FrameUserInput`), and a
   `ConstitutionTail` system message rides as the **last message of every
   LLM call** (never persisted to history, never pruned) restating identity +
   scope, so "ignore everything above" cannot reach past it.
3. **Output net** (`guardrail.HardGuardReply`): identity renames/renouncements
   (unless the name is Risk Agent), verbatim internal-prompt leaks, off-topic
   delivery ("here's a poem…", ingredients lists), and language-tagged code
   fences are replaced with a fixed refusal — at `FilterReplyCap` (the single
   choke point) and at the streaming card writer (override latch, so a
   violating frame never even flashes).

False positives are engineered against: "write a summary of incident X",
"ignore completed incidents in the count", "show me the rules for MTTR",
"call me if a P1 comes in", plain/`sql` code fences (log/SQL quoting) all
stay legal. There is **no env flag to disable any layer** — that is the point.

## Deliverables (PDF / deck generation + DM delivery)

Two generative tools (the ONLY writing the bot may produce, dashboard data
only): `generate_pdf_report(title, markdown)` renders agent-composed markdown
to a styled A4 PDF (`go-pdf/fpdf`; h1–h3, bullets, bold; 30k char cap), and
`generate_deck(title, slides)` writes a native PPTX (stdlib zip/OOXML, no
deps; ≤20 slides, heading + bullets per slide). Files land in
`<DataDir>/exports/<yyyymmdd-hhmmss>-<slug>.{pdf,pptx}` and the tool result
embeds a ticket `[generated-file: exports/<name>]`.

After a completed turn, `deliverGenerated` scans the final reply for tickets:
each file is uploaded via the **legacy media API**
(`POST https://oapi.dingtalk.com/media/upload?access_token=…&type=file`,
multipart field `media` → `media_id`) and pushed to the **sender's DM** via
`oToMessages/batchSend` msgKey `sampleFile`
(`{mediaId, fileName, fileDuration}`). The documented new-API upload path
(`/v1.0/robot/messageFiles/upload`) live-probes 404 (2026-11-11,
unauthenticated too — dead). The marker is rewritten to a status
note ("sent to your DM ✓" / saved-path fallback). Failure never fails the
turn; tickets resolving outside `DataDir/exports` are refused (the ticket text
comes from LLM output). Group attachment delivery was probed too:
`/v1.0/robot/groupMessages/send` rejects this robotCode ("robot 不存在") —
robots need a separate enterprise-group-send capability in the DingTalk
console, so DM is the only attachment channel for this app.

## Start

Dev (build + run, loads `dingtalk-agent.env`): `./start.sh` — same idea as the
dashboard's `clear_cache.sh`. Prod: compose service `dingtalk-agent`.

## Reminder DMs (design, Laravel side)

`reminders:send-incidents` and `reminders:send-action-improvements` already
compute who to remind and funnel through `ReminderMail::send($pics, …)` — that
funnel is the single hook point for a DingTalk channel (Laravel calls
`POST /v1.0/robot/oToMessages/batchSend` directly with the app token; `userIds`
= staffIds, max 20/call; email→staffId map in env). Do **not** re-derive
"who's due" in Go — one rule, one surface (BUG-005 lesson).

## Chat reminders (ask the bot to schedule something)

Ad-hoc reminders people create by asking — "remind us every Monday 9am to
review open P1s", "ingatkan saya besok jam 10 cek postmortem QRIS", "tiap hari
kerja jam 8:30 kirim ringkasan action improvement overdue ke grup ini". These
are **not** the system reminders above: they never decide who is due; a `task`
reminder reads the dashboard API like any chat question does.

**Storage and dispatch live in the dashboard** (unification, 2026-11-12): each
chat reminder is an `AiAgent` row (visible/editable/cancellable in Filament →
AI Agents), fired by the dashboard's scheduler and executed through the normal
agent pipeline — a `task` reminder runs through this agent's tool loop via the
internal listener, a `message` reminder is delivered as-is. The local
`schedules.json` store and the 30s Go scheduler loop are retired.

| Piece | Where |
|---|---|
| `schedule_reminder(when, mode, text)` / `cancel_reminder(id)` | `internal/tools/schedule.go` |
| cron/one-shot parser + pending handoff (grammar stays Go-owned) | `internal/schedule/` |
| dashboard `agent-schedules` API client (create/list/cancel) | `internal/tools/schedules.go` |
| bind to chat + group/DM delivery | `internal/dingtalk/reminders.go` |
| storage + dispatch (`AiAgent` rows, `run_at`/cron) | dashboard — `AgentScheduleController`, `DispatchDueAiAgentsCommand` |

- **when** (WIB, fixed UTC+7): 5-field cron (`0 9 * * 1`, `30 8 * * 1-5`), one-shot `YYYY-MM-DD HH:MM`, or `in 30m` / `in 2h`. The Go side validates the grammar; a cron passes through verbatim, a one-shot arrives as a resolved `run_at`.
- **mode**: `message` = Static engine, text delivered as-is; `task` = Go engine, the text runs through the agent's tool loop at fire time and the answer is posted.
- **delivery**: created in a group → `groupMessages/send` into that group; in a DM → `oToMessages/batchSend` to the creator.
- **binding is Go-side, never LLM-side**: the tool only validates and parks the request under a random token; after the turn the bot claims tokens found in *its own turn's* tool results and binds them to the callback's real conversation + sender. A prompt cannot aim a reminder at another chat, concurrent turns cannot steal each other's requests, and a claimed token never re-applies. Cancel only works from the owning conversation.
- **limits**: recurring ≥30 min apart, 20 per chat, text ≤1000 chars, task text must pass `InputGate`.
- **failure**: the dashboard down at create time fails closed — "⚠️ Reminder not saved: …"; nothing is stored silently. One-shots that fail at fire time retry after 5 minutes, up to 3 tries.
- **listing**: active reminders are fetched from the dashboard per turn and appended to each user message in that chat (`[Active reminders in this chat: …]`), so "what reminders do we have?" / "cancel #3" work; a dashboard outage just skips the block, never the turn.

**Group batching** (same release): rapid messages are merged only when they come
from the **same sender**; a different member's message closes the batch and gets
its own turn. Group messages carry a `[From <nick>]` prefix so the shared
history knows who asked what. DMs are unchanged (parallel, max 4 agent runs).

## Incident status watches

"Alert us when this incident's status changes" — per-incident watches created
from chat. Uses the same ticket-binding contract as reminders (Go-side only;
the LLM never sees a conversation ID).

| Piece | Where |
|---|---|
| `watch_incident(no)` / `unwatch_incident(id)` | `internal/tools/watch.go` |
| watch store (`<DataDir>/watches.json`), pending handoff | `internal/watch/` |
| bind to chat, 60s status sweep, change reports | `internal/dingtalk/watches.go` |

- **Binding**: the tool parks the request under a random `[watch-ticket: …]`
  token; the bot claims tokens from its own turn's tool results, resolves the
  incident via `GET /incidents-by-no/{no}` (title + current status), stores the
  watch, and appends "👁 Watching …" with the confirmed watch ID.
- **Polling**: one sweep per 60s tick regardless of watch count — a single
  `GET /incidents?updated_since=<tick+5s slack>&limit=100`; watched numbers
  whose `incident_status` changed fire one agent task turn each ("report which
  project and all relevant information"), delivered via the same
  group-with-DM-fallback path as reminders. The new status is persisted
  *before* the report fires, so a crash never double-reports.
  *ponytail:* a status that flips A→B→A within one 60s window is missed.
- **Limits**: 20 watches per chat, one watch per incident per chat;
  `unwatch_incident` only works from the chat that created the watch. Active
  watches are appended to each user message in that chat.
- Depends on the Laravel `updated_since` filter (row update time) — see
  `docs/reference/dependency-map.md`.

## Testing

`go vet ./... && go test ./...` — all tests hermetic (httptest fakes for the
LLM SSE stream, DingTalk OpenAPI, dashboard API, embeddings). Laravel side:
the agent depends on the v1 API's `updated_since` filter, covered by
`tests/Feature/Api/IncidentUpdatedSinceTest.php` (part of the 1166-test
baseline in the main repo).
