# Skills — the dingtalk-agent tool convention

Every skill (LLM tool family) gets its own folder with a `SKILL.md`. The
folder is the tracking unit: what the skill does, every surface it touches,
and what tests pin it. A new skill = a new folder + the checklist below.

**Why this exists:** 2026-11-12, the KB file-ingest feature registered
`save_knowledge` / `list_knowledge` / `delete_knowledge` but never touched the
prompt or the constitution — the tools worked, tests passed, and the model
refused to use them live. Plans enumerated code seams but not prompt surfaces.
This convention + `TestSkillDocsCoverRegistry` make the full surface list
explicit and enforced.

## The 9-step checklist (definition of done)

A new skill is done when ALL boxes tick and `go vet ./... && go test ./...`
is green. No box is optional — boxes 3 and 4 are the ones that historically
got skipped.

- [ ] **1. Define** — `xxxDef()` returns `llm.ToolDefinition{Name, Description,
      Parameters: schema(props, required)}`; handler wrapped in `typed[T]()`
      (`internal/tools/registry.go`). Never hand a raw string to `Parameters`:
      it double-encodes to `"parameters":"{…}"` and the gateway 400s the WHOLE
      chat request (2026-11-10 prod outage). Keep schemas FLAT — top-level
      object, properties of `type`+`description` only.
- [ ] **2. Register** — in the family's `Register*Tools` (or `NewRegistry`);
      `RegisterCapped` for anything that can return large results (loop
      default cap 8000 bytes; notion_read_page uses 48000).
- [ ] **3. Teach the prompt** — a family prompt const in
      `internal/agent/prompt.go` + a gate in `buildSystemPrompt`
      (`loop.go`, `registry.Has("<first tool of family>")`). Name EVERY tool
      in the prompt text — a tool the prompt doesn't mention is never chosen.
- [ ] **4. Constitution** — the tool must be allowed by `ConstitutionTail`'s
      scope bullet (`prompt.go`). A tool not named there is FORBIDDEN even
      when registered — the model will refuse it. This is the exact bug the
      convention exists to prevent.
- [ ] **5. Guardrails** — check the purpose against `internal/guardrail/`:
      `offTopicPatterns` / `reCodeFence` (scopegate) must agree with the scope
      line, or the output net eats the reply. Side-effectful chat tools park a
      request via `util.Pending` (16-hex ticket, `[0-9a-f]{16}` regex in the
      tool description) and the bot claims it AFTER the turn — never let the
      LLM supply a conversation id. Tool output auto-flows through `Redact`
      (secrets) and `TruncateToolOutput` — don't re-implement either.
- [ ] **6. Config gate** — family-conditional? Add a flag in
      `internal/config/config.go` (`AGENT_*_TOOLS` pattern, `loadBool`) + a
      row in `README.md`'s env contract table. Always-on families skip this.
- [ ] **7. Tests** — (a) a prompt-parity test per family, copying
      `TestKnowledgePromptTeachesAllKBTools` (`internal/agent/loop_test.go`):
      family prompt mentions every tool AND constitution names it; (b) a
      behavior test with `httptest` fakes + `t.TempDir()` (see
      `dashboard_test.go`'s `newTestRegistry`); (c) bump the fleet count in
      `TestAllToolSchemasFlat` (`deckpptx_test.go`) so the new tools ride the
      schema guard.
- [ ] **8. Docs** — new `skills/<family>/SKILL.md` (template below) + a row
      in `README.md`'s Tools table. `TestSkillDocsCoverRegistry` fails until
      the SKILL.md exists and lists the tools.
- [ ] **9. Mesh check** — solo tools reach WORKERS automatically (workers run
      the solo registry, `cmd/agent/main.go`). Leader-only tools go through
      `mesh.RegisterLeaderTools` inside `case "leader"` + a `LeaderPrompt`
      mention. `/internal/run` control-plane turns (PROJ-023) share the same
      registry — check the tool is safe there too.

## SKILL.md template

```markdown
---
name: <short-kebab-name>
status: shipped
tools: [tool_one, tool_two]
gate: always-on | AGENT_X_TOOLS (default on) | leader mode only
prompt: WhichPromptConst (gated on registry.Has("tool_one"))
files: [internal/tools/xxx.go]
---

# <Skill name>

## Purpose
When the model reaches for it — 2-4 lines.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `tool_one` | `a` (required), `b` | ... |

## Guardrails & caps
Result cap, redaction, ticket claiming, off-topic notes.

## Tests
File + what each test pins.

## Gotchas
The traps a maintainer must not re-trip.
```

Frontmatter keys `name`, `status`, `tools`, `gate`, `prompt`, `files` are
required — `TestSkillDocsCoverRegistry` parses the `tools:` line with a line
regex and asserts coverage of the registry (no YAML lib involved).

## Index

| Skill | Tools | Folder |
|---|---|---|
| Dashboard incidents/issues | search_incidents, get_incident, get_incident_markdown | [dashboard/](dashboard/SKILL.md) |
| Team knowledge base (RAG) | search/save/list/delete_knowledge | [knowledge/](knowledge/SKILL.md) |
| Notion technical docs | notion_search, notion_query_database, notion_read_page, notion_add_database | [notion/](notion/SKILL.md) |
| Web research | web_search, web_fetch | [web/](web/SKILL.md) |
| Deliverables | generate_pdf_report, generate_deck | [deliverables/](deliverables/SKILL.md) |
| External RCA (partner report) | generate_external_rca | [external-rca/](external-rca/SKILL.md) |
| Reminders & scheduled checks | schedule_reminder, cancel_reminder | [schedule/](schedule/SKILL.md) |
| Incident status watches | watch_incident, unwatch_incident | [watch/](watch/SKILL.md) |
| Mesh leader orchestration | delegate_task, list_workers, mission_status, cancel_mission, collect_results, set_mission_result, mesh_info | [mesh-leader/](mesh-leader/SKILL.md) |
