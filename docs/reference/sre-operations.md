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
