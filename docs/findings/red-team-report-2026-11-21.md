# Red-Team Security Audit — 2026-11-21

> **Fix status (2026-11-21, same day):** owner directive was to fix all findings. Fixes landed the same day — see the **Fix status** column added to the summary table below and the per-finding notes. One claim was DISPROVEN during fix planning (W6's "triple bcrypt": Laravel's `hashed` cast skips already-hashed values, `HasAttributes.php:1477`), and two items were DESIGN-ACCEPTED per owner decisions recorded in the codebase (W5's can_login=false SSO session; W9's persona-bodies-as-instructions). Pins now live in `tests/Feature/Security/RedTeamPoCTest.php` (inverted) + `tests/Feature/Security/RedTeamFixPinsTest.php` (new).

**Scope:** Laravel 12 + Filament 3 panel, Sanctum API, SSO, Go DingTalk agent, Docker/nginx deployment.
**Method:** full attack-surface sweep (3 parallel recon passes over API chain, web/Filament, Go agent + sink sweep) → manual source verification of every High/Critical claim → PoC suite `tests/Feature/Security/RedTeamPoCTest.php` (6 tests, 12 assertions, green on the current tree — each test documents the inversion expected when its finding is fixed).
**Rules honored:** report-only — no fixes applied (security-critical changes are owner-only per CLAUDE.md guardrails). Baseline untouched (suite run at the end of this report). *(Superseded same day by the fix directive — see Fix status.)*

Severity ranking = impact × exploitability given the current deployment.

---

## Summary table

| # | Severity | Finding | Surface | Fix status |
|---|----------|---------|---------|------------|
| W2 | **High** | Cross-team download of decrypted evidence & investigation documents | Web | **FIXED** |
| W1 | **High** | Stored XSS via recurrence `ai_analysis` (+ `javascript:` links) | Web | **FIXED** |
| W3 | **High** | `allowed_years` restriction bypassed by 2 AI incident-search endpoints | Web | **FIXED** |
| W4 | **Medium** | Open redirect on the evidence-download route | Web | **FIXED** |
| W6 | **Medium** | Access-request approval overwrites `allowed_years`; password triple-hash claim | Web | **FIXED** (years guard) / **DISPROVEN** (triple-hash — `hashed` cast skips already-hashed values) |
| W5 | **Medium** | SSO: UPN-only identity match, `can_login=false` session, JIT role for every member | Web | **FIXED** (azure_id binding) / **DESIGN-ACCEPTED** (can_login=false session — owner 2026-11-20 forwarded-peer intake flow) / **DESIGN-ACCEPTED** (JIT) |
| W8 | **Medium** | API token restrictions optional-by-default; permission cache staleness; public API docs | API | **FIXED** |
| W7 | **Medium** | Deployment: MySQL+Redis published unauthenticated; nginx no headers; `TrustProxies='*'`; session `secure` unset | Infra | **FIXED** (ports loopback, headers, dotfile deny, TrustProxies RFC1918, agent env narrowed) / **OWNER-ACTION** (SESSION_SECURE_COOKIE in prod env) |
| W9 | **Medium** | Go agent: empty allowlist, autonomous loop, unframed external content, persona library, plaintext secrets | Agent | **FIXED** (framing + client-side weekly-report validation) / **DESIGN-ACCEPTED** (personas-as-instructions, reminders-in-prompt) / **OWNER-ACTION** (rotate `dingtalk-agent.env` secrets, set `DINGTALK_ALLOWLIST`) |
| W10 | **Low/Med** | Dependency CVEs + dead XSS blade + `@unserialize` sink + unescaped shell | Deps | **FIXED** (dompdf 3.1.6, filament 3.3.52, framework 12.73, excel 3.1.70, phpspreadsheet 1.30.8; unserialize→regex; escapeshellarg; dead blade deleted) / **ACCEPTED** (livewire 3.7.3 kept — 3.8.x breaks Filament 3.3's testing macros across the suite; the DOM-XSS CVE fix rides the deferred Filament v4 upgrade; remaining advisories are dev-only/unreachable: phpunit, psysh, symfony/yaml, RichEditor which the app does not use) |
| W11 | **Info** | Hand-rolled HtmlSanitizer bypassable | Web | **FIXED** (callback + entity decode) |

**Deploy notes (owner):** run `php artisan migrate --force` on prod (2 new migrations: `2026_11_21_000001_backfill_allowed_endpoints_wildcard` — REQUIRED before the new middleware posture or live unrestricted tokens 403; `2026_11_21_000002_add_azure_id_to_users`). Rotate the secrets in `dingtalk-agent/dingtalk-agent.env` and populate `DINGTALK_ALLOWLIST`. `docker compose up -d --build` picks up the nginx/compose changes.

---

## W2 — Cross-team download of decrypted documents/evidence (High, PoC'd)

**Claim in one line:** the team-viewer access model (`Incident::scopeApplyUserYearAccess` is THE one visibility rule per CLAUDE.md) is fully bypassed by two download routes that gate on *permissions only*, so any team-viewer (JIT role, no admin) can download any other team's decrypted investigation documents and proof files by enumerating ids.

- `app/Http/Controllers/DownloadDocumentController.php:18` — gate = `manage incidents` OR `view incidents`. Every JIT team-viewer holds `view incidents` (seeder line 55-56). No team/PIC/year check. The file is **decrypted server-side** before streaming (`:45-47`).
- `app/Http/Controllers/DownloadActionEvidenceController.php:19` — gates through `ActionImprovementPolicy::view` (`app/Policies/ActionImprovementPolicy.php:22-25`) = `view incidents` OR `access api` — again permission-only, no record scoping. (Contrast: `ActionImprovementFormSchema::proofAllowed()` *does* scope submissions — downloads don't.)
- **PoC (green):** `RedTeamPoCTest::test_w2_team_viewer_downloads_another_teams_evidence` — viewer on team "IT Sec" downloads the encrypted evidence of an incident owned by team "SRE": HTTP 200 + `content-disposition` carries the plaintext filename. The same viewer's incident list correctly hides the incident (asserted in the test) — the download route is the counter-example.
- **PoC (gate-level):** `test_w2_team_viewer_downloads_another_teams_investigation_document` — the exact controller gate passes for a foreign-team viewer.
- **Exploit:** `for id in $(seq 1 2000); curl -b session /documents/$id/download` — each 200 is a decrypted sensitive document (investigation forms contain personal data: names, job roles, statements, chronology).
- **Fix:** gate both controllers on the incident record scope — `Incident::applyUserYearAccess($user)` existence check on the parent incident (the one-logic rule: extend `IncidentPolicy::view` to consult the team-viewer slice and call it from both controllers). Invert the PoC asserts to 403.

## W1 — Stored XSS on the incident view page (High, PoC'd)

**Claim:** AI-generated recurrence analysis renders raw HTML on the ViewIncident page; incident text (which external leads feed via the investigation-intake → AI chain) can steer that output. Plus `javascript:` links survive markdown conversion in the same field.

- Live converter behavior (verified with the project autoloader): league/commonmark via `Str::markdown()` escapes block-level `<script>` but **passes inline `<img src=x onerror=…>` and `[click](javascript:alert(1))` through verbatim**.
- The infolist path is safe: Filament `TextEntry::markdown()` runs `Str::sanitizeHtml()` after conversion (`vendor/filament/infolists/src/Components/Concerns/CanFormatState.php:286-290`).
- The hole is the ViewEntry blade rendered by the same page: `resources/views/filament/resources/incident-resource/pages/recurrence-alert.blade.php:42` — `{!! Str::markdown($aiAnalysis) !!}` with **no sanitizer**, fed from `Incident.recurrence_data.ai_analysis` (visible whenever `recurrence_data.is_recurring` is set — AI recurrence detection writes it).
- **PoC (green):** `RedTeamPoCTest::test_w1_raw_html_in_recurrence_ai_analysis_reaches_the_page` — `<img onerror>` and `href="javascript:..."` both emitted into the admin's page HTML.
- **Pin (green):** `test_w1_infolist_markdown_entries_are_sanitized` — proves the infolist path stays sanitized (this is the regression floor when you fix the blade).
- **Dead code note:** `resources/views/filament/resources/incident-resource/pages/view-incident.blade.php:142,165,188,211` carries the identical `{!! Str::markdown($inc->summary/root_cause/timeline/remark) !!}` pattern — but nothing renders this file (no reference anywhere; the live page is infolist-driven). **Delete it** — it is an XSS-shaped trap waiting for someone to re-wire.
- **Also flagged:** `app/Services/Notion/NotionBody.php:135-136` concatenates the Notion-controlled `name=` query param unescaped into emitted HTML — escape it at build time.
- **Fix:** wrap the recurrence blade output in `App\Support\HtmlSanitizer::clean(Str::markdown(...))` (the PDF blades already do exactly this — same one-liner), fix the sanitizer's scheme bypasses (W11), delete the dead blade.

## W3 — `allowed_years` bypassed by AI search endpoints (High, PoC'd)

**Claim:** users whose audit-log settings restrict them to specific years get those restrictions defeated by two search endpoints that query `Incident` raw.

- `routes/web.php:204-231` — `/admin/ai/chat/incident-search` inline closure: `Incident::where('no','LIKE')->orWhere('title')->orWhere('summary')` returning no/title/summary/severity/PIC/classification. Gate = `auth + can:access ai chat` only.
- `app/Http/Controllers/Ai/WarRoomIncidentSearchController.php:18-24` — same pattern behind `can:access war room`.
- Neither calls `applyUserYearAccess($user)` — the restriction in `UserAuditLogSetting` (allowed_years / can_view_all_logs) never applies.
- **PoC (green):** `RedTeamPoCTest::test_w3_ai_chat_incident_search_ignores_allowed_years` — a user pinned to the current year receives a 2-years-old incident row from the search.
- **Fix:** `Incident::query()->applyUserYearAccess($request->user())` in both places (also consider `countEligible` semantics if the search shouldn't surface `Issue`/`G` rows). Invert the PoC.

## W4 — Open redirect on evidence download (Medium, PoC'd)

- `app/Http/Controllers/DownloadActionEvidenceController.php:26` — `redirect()->away($record->link_url)` for link-type proofs; the store side (`ActionEvidenceStore::storeLink`) accepts whatever Filament `->url()` validation let through — `https://attacker.example` passes trivially (scheme/host, no allowlist).
- Any user with `submit action proof` (every JIT team-viewer) can plant the URL; the authenticated dashboard route then bounces to it — phishing value on the real domain.
- **PoC (green):** `RedTeamPoCTest::test_w4_link_proof_redirects_off_site_with_no_scheme_check` — Location header = the attacker URL.
- **Fix:** validate scheme (`https`) at store time AND re-check at redirect time (defense in depth — old rows exist). Invert the PoC.

## W6 — Access-request approval path (Medium)

- Anonymous `/request-access` (rate-limited 5/min, honeypot) → admin approves → `AccessRequest::approve()` (`app/Models/AccessRequest.php:87-127`):
  - **`UserAuditLogSetting::updateOrCreate` overwrites `allowed_years` with request-supplied values** — a user who had carefully restricted years gets them replaced by whatever the anonymous submitter asked for (social-engineering target: ask broad years).
  - **Triple bcrypt bug:** the form `Hash::make()`s the password (RequestAccessForm:128), the `AccessRequest.password` cast is `hashed` (AccessRequest.php:39 — hashes again on write), then `approve()` passes it to `User::firstOrCreate` whose own `hashed` cast hashes a third time → the created account **cannot log in with the password the requester chose** (owner must reset; SSO masks it).
- **Fix:** approve() should take the years from the admin form, not the raw request; drop one `Hash::make` level (remove `hashed` cast on AccessRequest or don't pre-hash in the form).

## W5 — SSO identity handling (Medium)

- `app/Http/Controllers/Auth/MicrosoftLoginController.php:27-33` — identity match = lowercase email = Entra UPN (`vendor/socialiteproviders/microsoft/Provider.php:204` maps `email => userPrincipalName`). **No Entra object-id binding** (`sub`/`id` never stored/compared), no `aud`/issuer check in app code (tenant-pinned client makes this acceptable, but the UPN-match is the weak link: a tenant admin renaming a UPN silently takes over the matching local account; `email_verified_at` self-stamped `:42`).
- `Auth::login()` runs **unconditionally** (`:56`) — `can_login=false` accounts get a live session; containment is only the zero-permission default. Check `can_login` **before** `Auth::login`.
- JIT (`:52-54`): every never-signed-in tenant member → `team-viewer` + `view incidents` + `access dashboard` + `submit action proof`, no allowlist. Note: with W2 fixed, team-viewers see nothing until a team is mapped — but they still hold `submit action proof` and the proof-submit surface.
- **Fix recs:** bind on Entra object id (store `azure_id` on first login, require match afterwards); gate `Auth::login` on `can_login`; make JIT opt-in allowlist if the tenant is large.

## W8 — API token model (Medium)

- **Empty `allowed_endpoints` = allow-all, and that's the form default** (`app/Filament/Resources/ApiTokenResource.php:123` helper text; `CheckApiTokenAccess.php:78` `empty($allowedEndpoints) → return true`). A "restricted" token is only restricted if someone remembered to tick boxes. **Token `abilities` are never consulted in the middleware at all** (only `read:pii` in resources) — `['*']` vs `['read:pii']` changes nothing at the endpoint layer.
- `CheckApiAccess.php:21` — the `access api` permission is cached 5 min with **no invalidation hook anywhere**; revoked users stay API-authorized up to 300 s.
- Meta-endpoint bypass (`CheckApiTokenAccess.php:40-41`) uses `str_starts_with` without a segment boundary — today only `/token/info` and `/logout` exist, but any future `api/v1/token-*` route silently inherits the bypass.
- `/docs.openapi` + `/docs.postman` (`routes/web.php:297-313`) — **anonymous**; the dir is named `app/private` but the route exposes the full API surface to anyone.
- Plaintext token parked in the session (`CreateApiToken.php:40`) until first view — persists in DB-backed sessions.
- Rate limits: `minValue(1)` no max; `TrustProxies='*'` (see W7) makes the per-IP fallback key spoofable.
- **Fix recs:** make empty-allowed-endpoints = deny (opt-in to allow-all explicitly), clear the cache on role/permission change, segment-bound the meta bypass, auth-gate or remove the docs routes.

## W7 — Deployment (Medium)

- **docker-compose publishes `db:33061` and `redis:6379` to the host.** Redis runs with **no `requirepass`** — unauthenticated Redis access from the host network = read/write sessions, cache, queue (`session`, `cache`, `queue` all live there); MySQL root = `DB_PASSWORD` (default `secret`). CLAUDE.md says prod deploys from this file. Remove the port mappings (compose-internal services don't need them).
- `dingtalk-agent` gets the **entire `.env`** via `env_file` — APP_KEY (which is the HMAC key for intake links AND the Crypt key for Notion tokens) and DB creds ride in a sidecar container env. Pass only what it needs.
- `nginx.conf`: deny regex `/\.(?:gitenv|gitignore)` is mangled (blocks neither `/.env` nor `/.git`; only mitigated by public-only root + the `public/storage` symlink); zero security headers (no CSP/XFO/HSTS/nosniff); `client_max_body_size 100M`.
- **`TrustProxies.php:11` `$proxies = '*'`** — any client can spoof `X-Forwarded-For/Proto`, poisoning `ApiAuditLogger` IPs, rate-limit bucket keys and the force-HTTPS hack in `AppServiceProvider`. Pin the real proxy subnet(s).
- `config/session.php:172` `secure => env('SESSION_SECURE_COOKIE')` — unset by default; with the http nginx listener the session cookie ships without `Secure`. Set it in prod env (and consider HSTS once TLS terminates).
- Sanctum `expiration => null` — expiry rests entirely on `expires_at` + the 90-day inactivity listener (`CheckTokenInactivity`); that's coherent, but note `redirect()->away` on evidence rows is one more reason to rotate keys.

## W9 — Go DingTalk agent (Medium)

- **`DINGTALK_ALLOWLIST` is empty in the dev env and `isAllowed` returns true for everyone** (`internal/dingtalk/stream.go:1153-1159`) — anyone the DingTalk app visibility reaches drives the agent; with W3-class data reachable through its tools. Autonomous 60-iteration tool loop (`loop.go:192-230`) with no user confirmation gate between tool calls.
- **Unframed external content into LLM context:** incident JSON/markdown (`dashboard.go:435,470-475`), weekly-report passthrough (`weeklyreport.go:59`), corpus (`corpus.go:84`) — unlike web/Notion/KB which are framed as untrusted data. **Skill/persona bodies are injected as instructions** (`skills.go:42-44`) — anyone who can edit the dashboard skill library injects every turn that loads it (stored prompt injection). Reminder text authored in chat is re-injected into the system prompt each turn (`stream.go:487-498`). Server error `message` strings pass through into agent context (`dashboard.go:108-126`).
- **`dingtalk-agent/dingtalk-agent.env`** — live-looking DingTalk client secret + `sk-…` AI key in plaintext, duplicated twice in the file, header says "rotate after testing". Rotate and move to proper secret storage.
- Internal listener `:9091` + mesh `:7801` bind **all interfaces**, plain HTTP, single shared token (constant-time compare is at least there); `/healthz` + `/statusz` unauthenticated info endpoints. Compose keeps them internal — fine until someone publishes ports.
- Weekly-report Go client silently converts invalid args (`weeks: -5`) into a full-year payload instead of erroring (`weeklyreport.go:42-52`) — minor scope-widening toward the 48k cap.
- **Fix recs:** set an allowlist; frame dashboard/corpus/weekly-report tool results with `FrameDocumentContent` like the other tools; treat skill/persona loading as data with an explicit "this is a role definition" marker; rotate the env secrets; bind listeners to `127.0.0.1` unless mesh is needed.

## W10 — Dependency CVEs (triaged by reachability)

`composer audit`: 37 advisories / 16 packages. Installed versions verified:

| Package | Installed | Advisories | Reachability verdict |
|---|---|---|---|
| dompdf/dompdf | 3.1.5 | CVE-2026-56722 local file read via SVG; chroot bypass; DoS ×2; existence oracle (fixed 3.1.6) | **Reachable in principle**: Post-Mortem PDF renders markdown from `retro_markdown` (AI output steered by incident text) — an inline SVG can probe local files. Upgrade to ≥3.1.6. |
| filament/filament | 3.3.46 | CVE-2026-48500 unauth temp-file upload on auth pages (fixed 3.3.52); CVE-2026-55409 RichEditor XSS; CVE-2026-48067 attach-scope | Unauth tmp upload applicable to the panel login page (disk-fill/spam); no RichEditor in use found. Upgrade filament to ≥3.3.52. |
| livewire/livewire | 3.7.3 | CVE-2026-81887 DOM-based XSS in client state handling (≤3.8.2) | Applicable everywhere Livewire renders. Upgrade ≥3.8.3. |
| maatwebsite/excel | 3.1.67 | CVE-2026-84374 writes outside disk via crafted filename (fixed 3.1.70) | Exports are admin-triggered with server-built names — low today. Upgrade ≥3.1.70. |
| phpoffice/phpspreadsheet | 1.30.6 | XXE via XmlScanner bypass; SSRF; ODS DoS | **No imports anywhere in app code** (exports only) — reader CVEs unreachable. Still upgrade when maatwebsite requires it. |
| laravel/framework | 12.70.1 | CVE-2026-128083 task output injection (<12.71.0) | Niche (concurrency process output). Upgrade ≥12.71. |
| psy/psysh | 0.12.18 | CWD `.psysh.php` auto-load privesc | Dev-only (tinker). |
| phpunit | dev | unsafe deserialization in coverage PHPT | Dev-only. |

- **`app/Services/EncryptionService.php:53-58`** — `@unserialize()` on decrypted legacy file content. Requires attacker-controlled ciphertext + key record; still delete the path (all current writes are plain strings) or replace with JSON.
- **`app/Console/Commands/ImportSecuritySkillsCommand.php:61-83`** — `exec("git clone --depth 1 {$repoUrl}")` + `exec("rm -rf {$tempDir}")` unescaped (`$repoUrl` = CLI option). Operator-only, but `escapeshellarg()` both.
- **`iamgerwin/php-pdf-to-markdown-parser:0.0.1`** — obscure, parses uploaded documents in the AI intake chain; treat input as hostile (it's fed user uploads) and watch for advisories on this package specifically.

## W11 — HtmlSanitizer bypasses (info, feeds W1/W9 fixes)

`app/Support/HtmlSanitizer.php:34` only strips quoted `javascript:`/`vbscript:`/`data:text/html` schemes (`(["\'])` capture) — **unquoted** `href=javascript:alert(1)` and entity-encoded variants pass. Its `DROP_ATTRS` regex `on[a-z]+` does catch handlers only when attribute matching also runs — verify against the W1 payloads after fixes. Long-term: prefer Filament's own `Str::sanitizeHtml` (Purifier-backed) everywhere instead of the hand-rolled regex sanitizer.

---

## PoC suite & verification

- `tests/Feature/Security/RedTeamPoCTest.php` — **6 tests / 12 assertions, green** on the current tree (host php, Colima down). Each test names its finding (W1–W4) and the inversion expected at fix time.
- The suite intentionally proves vulnerable behavior; when fixes land, invert asserts (403 instead of 200, absence instead of presence) and rename to pins.
- Full-suite baseline after this audit: **1516 tests / 6,056 assertions, OK (exit 0)** — host php, `vendor/bin/phpunit -d memory_limit=512M`, 7:05 min; the only "issues" are 10 PHPUnit deprecations (vendor noise). Count = 2026-11-20 baseline (1494/5,992) + parallel-session growth + these 6 PoC tests. No existing test touched.

## Suggested fix order

1. **W2** (download scoping — one scope call in each controller) + W4 redirect allowlist.
2. **W1** (one sanitizer call in recurrence blade; delete dead blade) + W11 sanitizer hardening.
3. **W3** (two scope calls in the AI search endpoints).
4. **W8/W7 config hygiene** (docs routes, allowlist semantics, Redis/MySQL port removal, TrustProxies pin, SESSION_SECURE_COOKIE).
5. **W6/W5** design fixes (approve() source of years; SSO id binding + can_login gate).
6. **Dependency upgrades** (dompdf 3.1.6+, filament 3.3.52+, livewire 3.8.3+, maatwebsite 3.1.70+, laravel 12.71+).
7. **Go agent hygiene** (allowlist, framing, secret rotation).
