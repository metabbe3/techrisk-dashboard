---
name: incident-watches
status: shipped
tools: [watch_incident, unwatch_incident]
gate: always-on
prompt: WatchPrompt (gated on registry.Has("watch_incident"), loop.go:326)
files: [internal/tools/watch.go, internal/watch/]
---

# Incident status watches

## Purpose
"Alert this chat when incident X changes status." The poller checks ~once a
minute and reports transitions (Open → In progress → Finalization →
Completed) with full incident context.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `watch_incident` | `no` (required, e.g. 20250115_IN_1234) | the bot verifies the incident and appends the confirmed watch ID after the reply — never invented by the model |
| `unwatch_incident` | `id` (required) | stops the watch; active watches for the chat are listed in the user's message context |

## Guardrails & caps
- Same `util.Pending` ticket-claiming pattern as reminders (`watch.Pending`,
  claimed after the turn) — never let the model supply a chat id.
- State lives in `data/watches.json` (poller thread sweeps it — the
  `dash`-nil guard exists because the poller runs without a bot).
- Constitution scope: incident monitoring is dashboard work — allowed by the
  incidents clause, no separate bullet needed.

## Tests
- `watches_test.go` — watch/unwatch against fakes; poller behavior.

## Gotchas
- Watch polling is `bot.RunWatchPoller(ctx)` started in the DingTalk run path
  (main.go) — a refactor that moves bot construction must not orphan it.
- The watch's status-change report is a full agent turn, not a static text —
  it shares the 4-slot `MaxConcurrentAgents` semaphore with chat turns.
