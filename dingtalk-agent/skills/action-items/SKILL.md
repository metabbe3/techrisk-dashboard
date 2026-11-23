---
name: action-items
status: shipped
tools: [search_action_items, get_action_item]
gate: always-on (core family)
prompt: search_action_items' own description carries the teaching (dashboard family, no separate prompt const)
files: [internal/tools/actions.go]
---

# Action items across incidents

## Purpose
The agent's cross-incident view of corrective/preventive actions: "what's
overdue this week", "what's on kevin@dana.id's plate", "which actions are
still open for incident 20250115_IN_1234". Replaces grepping per-incident
records.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `search_action_items` | status (exact vocab), overdue (bool), pending_approval (bool), pic (email), incident_no, limit (1-100, default 20), offset | `{meta: {total, limit, offset, returned, has_more}, data: rows}` — rows carry `incident_no`, `has_pending_proof`, `pending_proof_count` |
| `get_action_item` | `id` (from search rows) | one full record |

## Guardrails & caps
- Plain `Register` (default 8k cap); rows are already tight (Laravel
  `ActionImprovementResource`; `pic_email` rides only for tokens with
  `read:pii`).
- Laravel: `ActionImprovementController::search` (routes/api.php,
  `throttle:actions` group, `action-improvements` endpoint ability).
- Overdue = `GovernanceKpiService::overdueActionItemCount()`'s definition
  server-side (openStatuses + due_date < today; null due = TBD, never
  chased). Never re-derive overdue in Go (BUG-005 rule).
- Default scope = open work (`pending` + `on_progress` via
  `ActionImprovement::openStatuses()`); `status=` overrides with the exact
  vocabulary (hand-typed fakes 422).

## Tests
- `actions_test.go` — filter→query mapping, default/ceiling limit, by-id
  fetch, passthrough shape, count via meta.total pattern.

## Gotchas
- For "how many overdue" questions: `limit: 1` + read `meta.total`.
- `pic` matches the JSON pic_email column exactly (server-side
  whereJsonContains) — pass the full email.
