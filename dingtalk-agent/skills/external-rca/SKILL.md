---
name: external-rca
status: shipped
tools: [generate_external_rca]
gate: always-on
prompt: ExternalRCAPrompt (gated on registry.Has("generate_external_rca"))
files: [internal/tools/externalrca.go, internal/tools/templates/external-rca.docx, internal/tools/templates/build_external_rca_template.py]
---

# External RCA (partner-facing incident report, .docx)

## Purpose
The official partner-facing incident report in the DANA Word format — the
2026-11-05 Tokopedia QRIS report is the approved sample. The model composes a
draft from the incident record (optionally enriched from a matching Notion
postmortem), the requester confirms it IN CHAT, then the tool fills the
embedded template and the bot ships the .docx to whoever asked.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `generate_external_rca` | `incident_no` (required), `summary`, `problem`, `root_cause`, `actions` (one per line, ≤10), `chronology` (one per line `YYYY-MM-DD HH:MM \| event`, ≤30) | `[generated-file: exports/…docx]` marker |

- Header date = record's `incident_date` **+1 day** (`02 Jan 2006`) — computed
  in code from the dashboard record, never from the model.
- Title = `Incident Report for <incident_date> Incident` — fixed in the template.
- Actions/chronology may be numbered by the model; the tool strips prefixes and
  Word numbers the list 1..N via the shared numId.

## Guardrails & caps
- CONFIRM-FIRST flow is taught in the prompt and echoed in the constitution's
  deliverables bullet ("only after the requester confirmed the draft in chat").
- Wording rules (prompt): neutral, general, NON-technical; "DANA Team …"
  voice; no internal service/system/team/person names; never blame DANA;
  third party as CAUSE only when the record supports it (`third_party` /
  `incident_source=External`); no cause in record → generic phrasing, never
  invented.
- Chronology pacing (owner directive 2026-11-16): consecutive events ≤ 30
  minutes apart. Anchor times come from the record; when the record/Notion
  only has wide gaps, the model adds the necessary in-between steps with
  sensible interpolated times — anchors and event ORDER stay exactly as
  recorded (interpolation smooths pacing, never rewrites facts).
- Notion enrichment: only a doc VERIFIED to be about the same incident; same
  wording rules; dashboard record wins on conflict.
- Layout (owner directive 2026-11-16): unified Calibri 12pt throughout,
  body paragraphs justified + 1.5 line spacing, no literal-space indents —
  baked into the template by the builder script's `tidy_ppr`/`unify_fonts`.
- XML-escaped values; malformed chronology line → error naming the line.

## Tests
- `externalrca_test.go` — happy path (header/title/4 actions/9 rows),
  XML escaping, date rollover (2026-12-31 → 01 Jan 2027), malformed-line
  error, unknown incident, non-document parts byte-identical to the template.
- `TestExternalRCAPromptAndConstitution` (`agent/loop_test.go`) — parity net.
- `TestAllToolSchemasFlat` + `TestSkillDocsCoverRegistry` pick this tool up
  automatically (fleet count bumped to 17).

## Gotchas
- **Edit the template, not the code, to change the layout.** The template is
  the owner's real docx with placeholders; rebuild it from a fresh sample with
  `python3 templates/build_external_rca_template.py <sample.docx> external-rca.docx`.
  Every placeholder must stay single-run (Word splits text across runs; a
  multi-run placeholder is unreplaceable).
- `docProps/core.xml` creator is blanked — the sample carried a personal email.
- Handler errors surface as result text ("Error executing …"), not Go errors —
  the model reads and retries.
