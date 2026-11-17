---
name: web-research
status: shipped
tools: [web_search, web_fetch]
gate: AGENT_WEB_TOOLS (default on)
prompt: WebResearchPrompt (gated on registry.Has("web_search"), loop.go:314)
files: [internal/tools/web.go]
---

# Web research

## Purpose
Outside-world questions: vendor status pages, public outage reports, CVEs,
provider news. The prompt teaches "dashboard data ALWAYS wins on conflict"
and requires source URLs for web-sourced claims.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `web_search` | `query` (required) | top DuckDuckGo results: title, URL, snippet (keyless HTML scrape) |
| `web_fetch` | `url` (required, absolute http(s)) | readable text — HTML tags stripped, script/style dropped |

## Guardrails & caps
- **SSRF guard** (`web_fetch`): dialer `Control` hook resolves DNS first and
  REFUSES private/loopback/link-local IPs post-resolution — the intranet block
  is at the connection layer, not a URL regex. Don't replace the custom
  dialer with a plain `http.Client`.
- Fetch + search bodies read through `io.LimitReader(webFetchMaxBytes)`;
  search results self-truncate.
- DDG scrape is inherently brittle: zero results returns a HINT to fall back
  to `web_fetch` on a known URL (web.go:141), not an error.

## Tests
- `web_test.go` — `TestWebToolsDisabled` (gate off → tools absent),
  `TestWebFetchSSRFBlocked` (private target refused).

## Gotchas
- Both tools keyless (no API key) — a DDG page change or rate-limit shows up
  as "No results found … Try web_fetch", which is the designed degradation,
  not a bug. Treat a persistent all-empty search like the 2026-11-12
  TypeError lesson: a crash signal, not a content problem.
