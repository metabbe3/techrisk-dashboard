---
name: reminders
status: shipped
tools: [schedule_reminder, cancel_reminder]
gate: always-on
prompt: SchedulePrompt (gated on registry.Has("schedule_reminder"), loop.go:323)
files: [internal/tools/schedule.go, internal/tools/scheduleapi.go, internal/schedule/]
---

# Reminders & scheduled checks

## Purpose
"Remind me every Monday 9am to …" and — the powerful half — recurring `task`
reminders where the agent RUNS a request at fire time (daily digests via
`search_incidents updated_since`). Reminders persist as dashboard `AiAgent`
rows; the requester can see/edit/disable/run them from the dashboard's
AI Agents tab.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `schedule_reminder` | `when` (required: 5-field cron, `YYYY-MM-DD HH:MM` WIB, or `in 30m`), `text` (required, ≤1000 chars), `mode` (`message` default / `task`) | confirmation; the bot appends the real reminder ID after the turn — never invented by the model |
| `cancel_reminder` | `id` (required) | cancels; ids come from the user's message context (active reminders listed there) |

## Guardrails & caps
- **Ticket claiming**: the tool parks the request via `schedule.Pending`
  (`util.Pending[T]`, 16-hex token matching the `[0-9a-f]{16}` regex in the
  description); the bot claims it AFTER the turn in its own tool results
  (`dingtalk/reminders.go` `claimTickets[T]`) and binds the REAL chat id.
  Never trust the LLM with a conversation id — this is the pattern any new
  chat-binding tool must copy.
- Recurring crons must be ≥30 min apart; task text is framed through
  `guardrail.InputGate` before it ever reaches an LLM (reminders.go:76).
- Mode semantics on the control plane: `message` → static (dashboard posts
  verbatim, no LLM), `task` → Go engine run — the dashboard's
  `AgentScheduleController` mapping; grammar lives in `internal/schedule`.

## Tests
- `schedules_test.go` — tool behavior against `httptest` fakes of the
  dashboard schedule API (`scheduleapi.go` client).
- `internal/schedule` — cron/one-shot/relative parsing round-trips.

## Gotchas
- `scheduleapi.go` was renamed from `schedules.go` (README structure notes) —
  the dependency-map row for `AgentScheduleController.php` must keep pointing
  at the right file.
- Task texts are agent requests: the prompt tells the model to embed
  `updated_since` windows for digests; keep that example in SchedulePrompt
  accurate if the search tool's params change.
