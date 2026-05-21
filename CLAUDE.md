# Technical Risk Dashboard

**Stack:** Laravel 12.0 (PHP 8.2+) | Filament 3.2 | TailwindCSS 4.0 + Vite 7.0 | MySQL 8.0 | Redis | Docker

---

## Core Principle: Quality > Speed

Stop and reassess if you're tempted to skip tests, copy code without understanding, add "temporary" solutions, or work around problems instead of fixing them.

### Before ANY code is merged:
- [ ] Tests written and passing (unit + feature)
- [ ] Code reviewed
- [ ] No TODO/FIXME in production code
- [ ] Laravel Pint formatting applied
- [ ] No security vulnerabilities
- [ ] Lesson learned documented (if bug fix)

---

## PM Workflow (ALL requests flow through PM first)

**No agent should be invoked directly without PM knowledge and approval.**

| Agent | Use For | PM Approval |
|-------|---------|-------------|
| `backend-architect-engineer` | Backend work | YES |
| `frontend-engineer` | Frontend/UI work | YES |
| `backend-qa-engineer` | Backend QA | YES |
| `frontend-qa-specialist` | Frontend QA | YES |
| `database-architect` | DB schema changes | YES |
| `sre-engineer` | Infra/Docker/deployment | YES |
| `security-pentest-auditor` | Security review | YES |
| `architect-planning-design` | New features/planning | YES |
| `Explore` | Codebase exploration | PM initiated |

### Approval Gates

**Before work begins:** Request documented, impact assessed, conflicts with active projects checked, PM approval obtained.

**Before code merged:** Code review done, tests passing, docs updated, lesson learned (if bug), PM final approval.

---

## Directory Structure

```
app/
├── Actions/           # Single-action classes
├── Contracts/         # Interfaces
├── Enums/            # PHP 8.1+ enums
├── Exceptions/       # Custom exceptions
├── Filament/
│   ├── Resources/    # Filament resources
│   ├── Pages/        # Custom pages
│   ├── Widgets/      # Dashboard widgets
│   └── Components/   # Reusable components
├── Helpers/          # Utility functions
├── Http/
│   ├── Controllers/  # API controllers
│   ├── Middleware/   # Custom middleware
│   ├── Requests/     # Form request validation
│   └── Resources/    # API resources
├── Models/           # Eloquent models
├── Observers/        # Model observers
├── Providers/        # Service providers
└── Services/         # Business logic (domain-organized)
```

---

## Architecture Rules

- **Service Layer:** Complex business logic goes in `app/Services/{Domain}/`, not controllers. Use dependency injection.
- **Observers:** Use for side effects (notifications, cache invalidation, metrics). Never block — use queues for heavy operations.
- **Query Modification:** `IssueResource` extends `IncidentResource` with `getEloquentQuery()` filter for `classification = 'Issue'`.
- **Caching:** Use tag-based caching (`Cache::tags(['incidents'])->flush()`). Cache expensive queries.
- **Validation:** Use Form Request classes for all non-trivial validation.
- **API:** API Resources for responses, versioned routes (`/api/v1/...`), `ApiResponser` trait.
- **Queues:** Use queued jobs for time-consuming operations. Set `$tries` and `$timeout`.
- **Filament:** One resource per entity, Relation Managers for relationships, Sections/Tabs for complex forms.

---

## Docker Stack

Services: `app` (PHP-FPM 8.2), `nginx`, `mysql` (port 3306), `redis` (port 6379), `queue` (Laravel worker), `reverb` (WebSocket)

- DB: `mysql` / host: `db` / database: `laravel` / user: `root` / password: `password`
- Redis: host `redis`, port 6379
- Queue connection: `redis`
- Cache: `redis`
- Broadcast: `reverb`

---

## Testing Rules

- **Target:** 80%+ code coverage
- **Structure:** `tests/Unit/` for isolated tests, `tests/Feature/` for integration tests
- **Factories:** Use for all test data generation
- **RefreshDatabase** trait for clean state
- SQLite in-memory for fast test runs

---

## Security Rules

- Validate all user input (Form Requests)
- Parameterized queries (Eloquent handles this)
- Escape output (Blade handles this)
- CSRF protection enabled
- HTTPS only in production
- Keep dependencies updated (`composer audit`, `npm audit`)
- Role-based access via Spatie Laravel Permission
- API tokens via Laravel Sanctum with scoped abilities
- Audit logging via OwenIt Auditing for model changes

---

## Document Locations

```
docs/
├── projects/active-projects.md      # Track all ongoing work
├── bugs/lesson-learned.md           # Bug RCA and prevention
├── findings/findings.md             # Technical debt & findings
├── continuous-improvement.md        # Improvement initiatives
└── reference/                       # Detailed code examples & templates
    ├── backend-standards.md
    ├── frontend-standards.md
    ├── testing-standards.md
    ├── sre-operations.md
    └── project-management.md
```

---

## Quick Commands

```bash
composer dev                    # Start all services
php artisan test                # Run tests
./vendor/bin/pint               # Code style fix
php artisan optimize:clear      # Clear caches
docker-compose up -d --build    # Deploy
php artisan migrate --force     # Run migrations (prod)
php artisan pail                # View logs
php artisan queue:monitor       # Check queue status
```
