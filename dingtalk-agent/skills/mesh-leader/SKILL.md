---
name: mesh-leader
status: shipped
tools: [delegate_task, list_workers, mission_status, cancel_mission, collect_results, set_mission_result, mesh_info]
gate: leader mode only (GOAGENT_ROLE=leader)
prompt: LeaderPrompt (role switch in buildSystemPrompt, loop.go:307-312)
files: [internal/mesh/meshtools.go, internal/mesh/]
---

# Mesh leader orchestration (GoMesh/1.1)

## Purpose
When `GOAGENT_ROLE=leader`, the agent coordinates a worker cluster:
decompose a mission into subtasks, track them, synthesize results, persist
the final answer (survives restart via `set_mission_result`).

## Contract
| Tool | Params | Returns |
|---|---|---|
| `delegate_task` | `mission` (required), `subtasks` (required array: `goal` required, `context`, `capability`) | mission ID + subtask IDs |
| `list_workers` | — | roster: status, in-flight, capabilities, last seen |
| `mission_status` | `mission_id` (required) | mission + per-subtask state |
| `cancel_mission` | `mission_id` (required) | cancellation |
| `collect_results` | `mission_id` (required) | subtask findings for synthesis |
| `set_mission_result` | `mission_id`, `result` (required) | persists the final answer |
| `mesh_info` | — | cluster status |

## Guardrails & caps
- Registered ONLY in `case "leader"` (`cmd/agent/main.go:148`) via
  `mesh.RegisterLeaderTools(r, s)` — needs a live `mesh.Server`; not part of
  `NewRegistry`. `GOAGENT_MESH_TOKEN` is required in leader mode.
- A leader also runs the full solo registry (dual DingTalk, main.go) — leader
  tools ADD to chat tools, they don't replace them.
- Worker mode registers none of these; workers run assigned GOALS through the
  solo loop with the goal pre-framed via `guardrail.FrameDocumentContent` +
  `DocInjectionFlags` (main.go:176-181) — worker goals are UNTRUSTED content.

## Tests
- `internal/mesh/mesh_test.go` — `TestMeshHappyPathRegisterAssignReport` over
  real TCP; queue semantics.
- **No schema-net coverage**: `TestAllToolSchemasFlat` builds `NewRegistry`
  only, so leader tool schemas are outside it — and these defs hand-build
  `Parameters` map literals (not `schema()`). Map literals marshal as
  objects, so they pass the wire — but a new leader tool must still never
  assign a raw STRING to Parameters (the 2026-11-10 gateway 400).

## Gotchas
- The constitution's scope bullet does NOT name the leader tools — mesh
  missions are allowed under the incidents/dashboard-work clause. If a leader
  tool ever reaches beyond that scope, the constitution bullet AND
  `LeaderPrompt` must be widened in the same commit (the 2026-11-12 lesson,
  mesh edition).
- `TestSkillDocsCoverRegistry` pins these 7 names by hand-list —
  `RegisterLeaderTools` needs a live server to enumerate them for real; wire
  one in if this family grows.
