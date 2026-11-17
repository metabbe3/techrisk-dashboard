---
name: deliverables
status: shipped
tools: [generate_pdf_report, generate_deck]
gate: always-on
prompt: DeliverablesPrompt (gated on registry.Has("generate_pdf_report"), loop.go:320)
files: [internal/tools/reportpdf.go]
---

# Deliverables (PDF / deck)

## Purpose
"Make me a PDF report / deck of …" — the model composes the content from tool
results first (search → get_incident / get_incident_markdown), then renders.
The constitution's deliverables bullet is the ONLY document-writing permission:
never poems/stories/essays/code, even "for debugging".

## Contract
| Tool | Params | Returns |
|---|---|---|
| `generate_pdf_report` | `title`, `markdown` (both required) | `[generated-file: exports/<name>.pdf]` marker — the model must include it VERBATIM in the reply; the bot scans replies for the marker and DMs the file |
| `generate_deck` | `title`, `slides` (both required; markdown blocks, `## Heading` + `- ` bullets) | same marker with `.pptx` |

## Guardrails & caps
- Markdown body cut at `maxReportMarkdown` = 30000 chars; deck: max 20
  slides, 15 bullets each.
- Files land in `<DataDir>/exports/` with WIB stamp + 6-hex random suffix —
  two parallel calls in the same second used to overwrite each other
  (exportPath comment).
- `ensureRenderable` validates markdown structure before render.
- Delivery is bot-side (`push.go` `deliverMedia`), not the tool's job — the
  tool only writes the file and returns the marker.

## Tests
- `reportpdf_test.go` — render happy paths + marker format.
- `deckpptx_test.go` — slide parsing + **`TestAllToolSchemasFlat`**, the
  whole-fleet schema net (:151): every tool's Parameters must marshal as a
  JSON object with flat `type`+`description` properties. Bump its fleet count
  when adding a family.

## Gotchas
- The 2026-11-10 prod outage started here: a raw-string `Parameters`
  double-encodes to `"parameters":"{…}"` and the gateway 400s the WHOLE chat
  request. `schema()` exists because of that — new tools must use it.
- The marker constant is `GeneratedFileMarker` (reportpdf.go:32) — the reply
  filter and bot scan depend on its exact shape; change both together.
