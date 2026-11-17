---
name: dashboard-incidents
status: shipped
tools: [search_incidents, get_incident, get_incident_markdown, get_dashboard_stats]
gate: always-on (core family)
prompt: SystemPrompt "Available Tools" section (prompt.go:9) — taught in the BASE prompt, not a gated family; get_dashboard_stats adds a StatsPrompt section (prompt.go, registry.Has-gated)
files: [internal/tools/dashboard.go]
---

# Dashboard incidents & issues

## Purpose
The agent's reason to exist: answer questions about incidents, issues,
severities, counts, fund losses. The model calls these before anything else on
dashboard topics; the prompt's Domain Rules section teaches the counting
recipes (official = raw − G − Non Incident) and incidents-vs-issues cross-checks.
`get_dashboard_stats` replaces those recipes with the dashboard's OWN aggregates —
prefer it for any MTTR/MTBF/fund-loss/per-group question.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `search_incidents` | severity, status, classification, fund_status, type, tags, search, start_date, end_date, updated_since, min/max_fund_loss, min/max_potential_fund_loss, limit (1-100, default 20), offset | Laravel envelope: `meta.total` (RAW count) + `data` rows |
| `get_incident` | `no` (e.g. 20250115_IN_1234, preferred) or `id` | full record incl. action improvements, fund loss |
| `get_incident_markdown` | `no` (required) | complete report as Markdown (base64-decoded server-side) |
| `get_dashboard_stats` | mode (summary default / by_dimension), metric, dimension, start_date, end_date, severities, incident_types, statuses, fund_statuses (lists comma-separated) | summary: `totalCases, avgMttrMins, avgMttrDays, avgMtbf, totalFundLoss, totalPotentialFundLoss, totalRecoveredFund`; by_dimension: `labels[]/values[]/raw_data[]` |

## Guardrails & caps
- Plain `Register` — default 8000-byte loop cap; `get_incident_markdown` rides
  `RegisterCapped` at 48 KiB (dashboard.go:193).
- All four are GET-only reads through the dashboard API (`DASHBOARD_API_BASE`
  + bearer token). No ticket claiming needed. The agent token needs the
  `stats` endpoint in its `allowed_endpoints` (Filament → API Tokens) unless
  unrestricted.
- `meta.total` is a RAW count (includes G / Non Incident / Issue rows) — the
  subtraction recipe lives in the system prompt; don't "fix" it here. For
  official numbers `get_dashboard_stats` is the source: the Laravel endpoint
  delegates verbatim to `IncidentStatsFooterData::build()` /
  `AnalyticsQueryService::buildSingleDataset()` (zero new math — one-logic
  rule), so its numbers already exclude outliers and split the MTTR sign.
  Quote them as-is; never re-math on top.

## Tests
- `dashboard_test.go` — `newTestRegistry` (httptest server faking the Laravel
  envelope) + `envelope()` helper; pins query-param mapping, bearer token,
  method per tool (`TestSearchIncidentsMapsParamsToQuery` et al.);
  `TestStats*` pins the stats tool's mode defaulting, bracket-array list
  encoding, date pairing, and by_dimension validation.

## Gotchas
- `updated_since` filters on row updates (RFC3339 or YYYY-MM-DD) —
  start/end_date filter incident_date, which never moves. Digest reminders
  depend on this distinction.
- A lone `start_date` means "until today" on both tools (pairDates).
- The Laravel counterpart controllers are `AgentScheduleController` /
  `AgentRunController` / `StatsController` — see
  `docs/reference/dependency-map.md`.

