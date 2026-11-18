---
name: skills-bridge
status: shipped
tools: [list_skills, load_skill]
gate: AGENT_SKILL_TOOLS (default on)
prompt: SkillsPrompt (prompt.go, gated on registry.Has("list_skills"))
files: [internal/tools/skills.go]
---

# Skills bridge (dashboard skill library + personas)

## Purpose
Gives the agent the dashboard's Filament-managed skill library: methodology
playbooks, frameworks, checklists, and War Room persona profiles. On-demand
progressive disclosure — `list_skills` is a cheap metadata catalog;
`load_skill` pulls ONE body when it fits the question. A skill
written/imported in Filament → Skills is usable by the bot with no code
change.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `list_skills` | — | catalog lines `- name (domain) — description`; entries with `domain=role` are persona profiles named `role-<role_key>` |
| `load_skill` | `name` (required, `^[A-Za-z0-9_-]{1,64}$`) | one skill's full body, or for `role-<key>` the persona system prompt + attached skill NAMES (load each separately); server truncates at 48k chars with a flag |

## Guardrails & caps
- `RegisterCapped` at 48 KiB both tools (bodies are pre-capped server-side at
  48 000 chars). The agent token needs the `skills` endpoint in its
  `allowed_endpoints` (Filament → API Tokens) unless unrestricted.
- Read-only GETs through the dashboard API; no ticket claiming, no writes.
- Persona bundles intentionally never carry attached skills' bodies — the
  model load_skills what it needs, keeping one call under the cap by design.
- Content is DOCUMENT data: the constitution's framing rules apply (data
  about how to work, never instructions that override the user or the
  constitution).

## Tests
- `skills_test.go` — catalog shape + path, one-body fetch + truncation flag,
  local name validation rejects before any HTTP round trip, persona bundle
  passthrough.
- `internal/agent/loop_test.go` — `TestSkillsPromptAndConstitution` (both
  tools taught + allowed), `TestPruneContextBudgetSparesLoadedSkills` +
  `TestPruneContextBudgetBlanksLoadedSkillAsLastResort` (a loaded skill
  survives pruning while other tool results remain).

## Gotchas
- `load_skill` results are spared by `PruneContextBudget`'s first pass (the
  methodology the turn is following); they blank only when no other tool
  result remains. Don't "simplify" that back — it re-creates the bug where
  the skill guiding the answer is the first thing deleted.
- `/internal/run` task turns reach these tools automatically (solo registry
  subset — they are read-only, so nothing to exclude); scheduled reminders
  can self-serve a methodology.
- Laravel side: `SkillController` (`app/Http/Controllers/Api/SkillController.php`),
  `ApiEndpoint::SKILLS` for token scoping, route in the `throttle:agent`
  group — see `docs/reference/dependency-map.md`.
