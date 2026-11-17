---
name: notion-docs
status: shipped
tools: [notion_search, notion_query_database, notion_read_page, notion_add_database]
gate: AGENT_NOTION_TOOLS (default on)
prompt: NotionPrompt (gated on registry.Has("notion_search"), loop.go:317)
files: [internal/tools/notion.go]
---

# Notion technical documents (read-only)

## Purpose
PRDs, TAs, design docs, postmortems. The model searches → queries a NAMED
database → reads pages ONE AT A TIME, and cross-checks Notion content against
dashboard data (dashboard wins on incident/issue conflicts).

## Contract
| Tool | Params | Returns |
|---|---|---|
| `notion_search` | `query` (required) | page titles + URLs (dashboard MCP proxy, `/notion/search`) |
| `notion_query_database` | `database` (catalog name or raw id/URL), `query` (SQL) | rows; caps at 100 — page with `WHERE createdTime >= '<last row createdTime>'` |
| `notion_read_page` | `id_or_url` (required) | full page as Markdown, 48k cap |
| `notion_add_database` | `name` (1-32 chars), `id` | teaches the catalog a short name; raw ids auto-save as `db-<8hex>` after first query |

## Guardrails & caps
- All four go through the Laravel Notion MCP proxy (`DASHBOARD_API_BASE`) —
  the agent never talks to Notion directly; OAuth lives dashboard-side (see
  memory: Notion OAuth on VPN-only domain).
- `notion_read_page` rides `RegisterCapped` at 48000 bytes (notion.go reg).
- Read pages record into a ctx sink (`recordRead` / `NotionRead`,
  `tools.WithNotionSink(runCtx)` + `TakeNotionReads`) so control-plane runs
  (PROJ-023 `/internal/run`) can report what was read. Any new "record reads
  for post-turn reporting" feature must copy this pattern.
- Write APIs are NOT exposed — read-only by design; `notion_add_database`
  only edits the LOCAL catalog file, not Notion.

## Tests
- `notion_test.go` — httptest fakes for the proxy; `TestNotionKillSwitch`
  (gate off → no tools), `TestNotionResultCaps`.
- `notionlearn_test.go` — the auto-learn/auto-naming paths.

## Gotchas
- `notion_query_database`'s description is REBUILT from the live catalog on
  every registration (notion.go:303-311) so the model always sees current
  names — don't freeze the name list into the prompt const.
- Notion read tools are also embedded (Go-side) in investigation-page flows on
  the dashboard (`NotionBody`); those share the MCP server, not this code.
