# SRE Operations (Reference)

Docker configuration, monitoring, backup, and performance standards. Read this when working on infrastructure.

---

## Docker Container Architecture

```yaml
services:
  app:     # PHP-FPM 8.2
  nginx:   # Web server
  mysql:   # MySQL 8.0
  redis:   # Redis cache/queue
  queue:   # Laravel queue worker
```

### Health Checks

Configure for all services:

```yaml
healthcheck:
  test: ["CMD", "php-fpm-healthcheck"]
  interval: 30s
  timeout: 10s
  retries: 3
```

### Resource Limits

```yaml
deploy:
  resources:
    limits:
      cpus: '1'
      memory: 1G
    reservations:
      cpus: '0.5'
      memory: 512M
```

## Monitoring & Logging

### Logging Standards

Use **Laravel Pail** for log monitoring: `php artisan pail`

```php
Log::info('Incident created', [
    'incident_id' => $incident->id,
    'user_id' => auth()->id(),
]);
```

### Queue Monitoring

```bash
php artisan queue:monitor high-priority,default --max=100
```

### Cache Management

- Monitor cache hit rates
- Set appropriate TTLs
- Use tag-based cache invalidation

## Backup Strategy

### Database Backups
- Daily automated backups
- Retention: 30 days
- Off-site storage

### File Storage
- Document encryption at rest
- Secure file deletion policies
- Regular access audits

## Performance Optimization

### Database Query Optimization

Use **Eager Loading** (`with()`) to prevent N+1 queries. Add indexes for commonly queried fields.

```php
// Bad - N+1 query
$incidents = Incident::all();
foreach ($incidents as $incident) {
    echo $incident->pic->name; // Queries each time
}

// Good - Eager loading
$incidents = Incident::with('pic')->get();
```

### API Response Optimization

```php
// routes/api.php
Route::middleware('throttle:60,1')->group(function () {
    Route::apiResource('incidents', IncidentController::class);
});
```

- Use pagination for large datasets
- Implement rate limiting
- Cache expensive computations

## Profiling (2026-11-19)

### Laravel dashboard
- **Debugbar** (dev-only, `composer.json` require-dev; prod deploys run `composer install --no-dev` so it never ships):
  - Per-request data lands in `storage/debugbar/*.json` (`__meta.uri`, `queries.statements`, `time.measures`, `livewire`).
  - CLI readout: `python3 -c` over those JSONs — query count, `accumulated_duration_str`, slowest statements.
  - Browser: logged-in flow via seeder admin (`admin@example.com` / `password` — dev only). The login is Livewire, not a form POST; drive it with a real browser.
- **In-app instrumentation** (`AppServiceProvider::boot()`): `Model::preventLazyLoading(true)` + violation logger (`[N+1]` warnings, log-only, never throws) and `DB::whenQueryingForLongerThan(500)` → `[SlowQueries]` warnings (10s dedupe per connection). Grep `storage/logs/laravel.log` for both tags.
- ⚠ Compiled PHP (providers) is cached by OPcache in the app container — provider/boot changes need `docker compose restart app` before Debugbar reflects them.
- Round-1 result (2026-11-19): DB layer lean (no N+1, no >500ms queries); the one per-request cost was `MailSettings::apply()`'s `Schema::hasTable` → replaced with `Setting::tableExists()`. Details in `docs/findings/findings.md` [P-1].

### dingtalk-agent (Go)
- **pprof**, opt-in: set `AGENT_PPROF_ADDR=127.0.0.1:6060` in `dingtalk-agent.env` (localhost-only; `config.Load` refuses other binds — heap exposure). Restart the agent, then from the host:
  ```bash
  # inside the container's network namespace
  docker compose exec dingtalk-agent sh -c 'wget -O /tmp/cpu.prof http://127.0.0.1:6060/debug/pprof/profile?seconds=30'
  docker compose cp dingtalk-agent:/tmp/cpu.prof .
  go tool pprof -http=:0 cpu.prof
  ```
  Profile types: `profile` (CPU, 30s), `heap`, `goroutine`, `trace`. Keep `AGENT_PPROF_ADDR` unset outside profiling sessions.
- **Benchmarks**: `go test ./internal/guardrail/ ./internal/agent/ -run XXX -bench . -benchmem` (FilterReplyData, PruneContextBudget — the CPU-bound paths; LLM/HTTP wait dominates live turns, so goroutine profiles matter more there).
- Baselines (2026-11-19, M2): FilterReplyData ~52ms/op @ ~64KB, PruneContextBudget ~39µs/op @ 100k budget.
