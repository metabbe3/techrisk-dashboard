# Design: Action Improvement status "On progress" — 2026-11-18

## Intent
Add an **On progress** status to action improvements: accepted **and** started, not finished. Slots between `pending` and `done` in the existing lifecycle (`draft → pending → on_progress → done | cancelled`, `tbd` stays a parked state).

## Owner decision (2026-11-17, in-session)
**Rule A — still chased:** on_progress rides every pending-gated surface. An in-progress action past its due date is reminded, escalated, and counted as overdue exactly like pending. Only `done` / `cancelled` / `tbd` stop the chasing. (Rejected alternative B — "parked once started" — would let started actions rot silently past due date.)

## Rule (one sentence)
`on_progress` is **open work**: everywhere the code gates on `status = 'pending'` for chasing or open-work counting, the gate becomes `status IN ('pending', 'on_progress')`. Date-TBD semantics (open row with `due_date = null`) extend to on_progress rows: tracked, never chased — there is no date to chase against.

## Surfaces touched (grep-verified)
| File | Change |
|---|---|
| `app/Models/ActionImprovement.php` | `STATUS_ON_PROGRESS` const + `STATUSES` + lifecycle comment |
| `app/Filament/Actions/ActionImprovementFormSchema.php` | Select option "On progress" (via new shared `statusOptions()`), `statusColor` = `info` (blue) |
| `app/Console/Commands/SendActionImprovementReminders.php` | 2× `where('status','pending')` → `whereIn` (due-soon + overdue; escalation rides the overdue batch) |
| `app/Filament/Widgets/ActionImprovementsOverview.php` | pending tile sums pending+on_progress; overdue/escalated CASEs `IN`; tbd rider = open rows with no due date |
| `app/Services/Governance/GovernanceKpiService.php` | KPI open-gate → `whereIn` |
| `app/Services/Ai/ChatContextService.php` (×2) | AI overdue-action context (count + rows) → `whereIn` |
| `app/Filament/Resources/ActionImprovementResource.php` | `$overdue` scope, status filter options (shared helper), overdue ternary NEGATION (`!= pending` → `notIn [pending,on_progress]`) |
| `ActionImprovementsRelationManager.php` | `isOverdue`/`isTbd`, status filter options, overdue ternary negation, send-reminder visible |

Unchanged: `completed_at` still only on `done`; `result_note` only on Done; `cancellation_reason` only on Cancelled; war-room `is_completed` flag untouched.

## Guardrails honored
- Enum filter values from `ActionImprovement::STATUS_*` consts, never hand-typed (BUG-005/006/007 rule 1).
- Status feeds dashboard/chase numbers → every gate in this table got the `whereIn`, not one call site (rule 3).
- Negation ternaries updated on BOTH surfaces — a stale `!= pending` would silently drop on_progress rows from "not overdue" (rule 4).

## Docs
`docs/reference/business-rules.md` action-improvement section updated in the same commit.

## Tests
New `tests/Feature/ActionImprovementOnProgressTest.php` mirroring the TbdStatus test patterns: reminder chase (overdue + escalation with a manager chain), widget tiles via `Closure::bind(getStats())`, tbd rider, shared `statusOptions()`, badge color, filters on both surfaces (status + overdue ternary), send-reminder visibility, KPI, AI context. Existing status tests stay green (baseline must not drop).
