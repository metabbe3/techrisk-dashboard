---
name: governance
status: shipped
tools: [get_governance_kpis, list_reference]
gate: always-on (core family)
prompt: get_governance_kpis' own description carries the teaching (dashboard family)
files: [internal/tools/actions.go]
---

# Governance KPIs + reference vocabularies

## Purpose
Governance/PDR questions ("how's RCA within 5 days doing?", "how many overdue
action items?") answered with the dashboard's OWN numbers — the same
`GovernanceKpiService` the Filament Governance page shows. Plus
`list_reference` for the exact filter vocabularies (labels / incident types /
categories) so `search_incidents` filters stop being guessed.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `get_governance_kpis` | year (int, default current), series (bool → lag_by_quarter labels/fl/nfl) | `GovernanceKpiService::summary()` verbatim + series |
| `list_reference` | kind: `labels` \| `incident-types` \| `categories` | the reference list |

## Guardrails & caps
- Laravel: `GovernanceController` (`routes/api.php`, `throttle:agent` group,
  `governance` endpoint ability); reference kinds reuse the existing
  `labels` / `incident-types` / `categories` routes and abilities.
- ZERO new metric math (one-logic rule): values are quoted, never re-computed
  on the Go side or in answers. Reference lists are the ONLY allowed filter
  vocabularies.

## Tests
- `actions_test.go` — passthrough keys, series/year query, kind→path mapping,
  unknown kind rejected locally before any HTTP round trip.

## Gotchas
- `rca_within_5d.pct` can be null (no completed-with-RCA rows in scope) —
  report "not enough data", never 0.
