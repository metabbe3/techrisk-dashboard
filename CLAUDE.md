# Technical Risk Dashboard - Standard Operating Procedure

## Project Overview

**Technical Risk Dashboard** is an enterprise Laravel-based incident and issue management system for tracking technical incidents, root causes, financial impact, and remediation actions.

- **Framework:** Laravel 12.0 (PHP 8.2+)
- **Admin Panel:** Filament 3.2
- **Frontend:** TailwindCSS 4.0 + Vite 7.0
- **Database:** MySQL 8.0+ with Redis caching
- **Deployment:** Docker (app, nginx, mysql, redis)

---

## Core Principle: Quality > Speed

**"Quality is not an act, it is a habit." - Aristotle**

This project prioritizes **quality over speed** in all aspects of development. This principle applies to every role, every decision, and every line of code.

### What This Means

| Aspect | Quality First Approach | Avoid |
|--------|----------------------|-------|
| **Code** | Clean, tested, documented | Quick hacks, tech debt |
| **Features** | Well-planned, properly scoped | Rushed, half-baked |
| **Bugs** | Proper RCA, prevention | Quick fixes, band-aids |
| **Reviews** | Thorough, thoughtful | Rubber-stamp approvals |
| **Testing** | Comprehensive coverage | Minimal or no tests |
| **Documentation** | Clear, up-to-date | Outdated or missing |

### Quality Checklist

Before ANY code is merged, verify:

- [ ] Tests written and passing (unit + feature)
- [ ] Code reviewed by at least one person
- [ ] Documentation updated (CLAUDE.md, API docs, comments)
- [ ] No TODO/FIXME comments in production code
- [ ] Laravel Pint formatting applied
- [ ] No security vulnerabilities
- [ ] Lesson learned documented (if bug fix)
- [ ] Performance impact considered
- [ ] PM approval obtained

### When to Slow Down

**Stop and reassess if:**
- You're tempted to skip tests
- You're copying code without understanding it
- You're adding "temporary" solutions
- You're unsure about the architecture
- You're working around a problem instead of fixing it
- You're about to commit without review

**Remember:**
> "The bitterness of poor quality remains long after the sweetness of meeting the deadline has been forgotten."

---

## Table of Contents

1. [Project Management (PM) - Central Workflow](#project-management-pm---central-workflow)
2. [Backend Engineering (BE)](#backend-engineering-be)
3. [Frontend Engineering (FE)](#frontend-engineering-fe)
4. [Quality Assurance (QA)](#quality-assurance-qa)
5. [Site Reliability Engineering (SRE)](#site-reliability-engineering-sre)
6. [Security & Compliance](#security--compliance)
7. [Database Management](#database-management)
8. [Testing Standards](#testing-standards)
9. [Continuous Improvement](#continuous-improvement)

---

## Project Management (PM) - Central Workflow

### IMPORTANT: All Requests Flow Through PM

**All development requests, bug reports, and feature requests MUST be directed to the PM first.**

#### PM Workflow Diagram

```
User/Stakeholder Request
         ↓
    ┌──────────┐
    │    PM    │ ← Central Point of Contact
    └────┬─────┘
         │
         ├──→ Assess & Prioritize
         │    └──→ Check Active Projects
         │    └──→ Review Findings
         │    └──→ Consult Lesson Learned
         │
         ├──→ Is it a BUG?
         │    ↓ YES
         │    ├──→ Document in docs/bugs/lesson-learned.md
         │    ├──→ Root Cause Analysis
         │    ├──→ Prevention Strategy
         │    └──→ Then assign to appropriate agent
         │
         ├────→ Is it a NEW FEATURE?
         │    ↓ YES
         │    ├──→ Add to docs/projects/active-projects.md
         │    ├──→ Technical Breakdown (use architect-planning-design agent)
         │    ├──→ Resource Estimation
         │    └──→ Assign to agents with approval
         │
         └───→ Is it ENHANCEMENT?
              ↓ YES
              ├──→ Review existing code
              ├──→ Impact Analysis
              └──→ Assign with approval
```

### PM Responsibilities

#### 1. Request Triage
- **ALL incoming requests** first go to PM
- Categorize: Bug / Feature / Enhancement / Question
- Assess urgency and impact
- Check against active projects to avoid duplication

#### 2. Agent Coordination
PM manages ALL specialized agents:

| Agent | Triggered By | PM Approval Required |
|-------|--------------|---------------------|
| `backend-architect-engineer` | Backend work | ✅ YES |
| `frontend-engineer` | Frontend/UI work | ✅ YES |
| `backend-qa-engineer` | Backend QA | ✅ YES |
| `frontend-qa-specialist` | Frontend QA | ✅ YES |
| `database-architect` | DB schema changes | ✅ YES |
| `sre-engineer` | Infra/Docker/deployment | ✅ YES |
| `security-pentest-auditor` | Security review | ✅ YES |
| `architect-planning-design` | New features/planning | ✅ YES |
| `Explore` | Codebase exploration | ⚠️ PM initiated |
| `feature-strategist` | Feature ideation | ✅ YES |

**IMPORTANT:** No agent should be invoked directly without PM knowledge and approval.

#### 3. Approval Gates

**Before ANY work begins:**
1. ✅ Request documented in appropriate tracker
2. ✅ Impact assessed
3. ✅ Resource requirements estimated
4. ✅ Conflicts with active projects checked
5. ✅ PM approval obtained

**Before ANY code is merged:**
1. ✅ Code review completed
2. ✅ Tests passing
3. ✅ Documentation updated
4. ✅ Lesson learned entry (if bug fix)
5. ✅ PM final approval

#### 4. Project Tracking

**Active Projects Document:** `docs/projects/active-projects.md`

For each project, PM maintains:
- Project name and ID
- Description and business value
- Assigned agents/team members
- Current status (Backlog / In Progress / Review / Done)
- Dependencies
- Blockers
- Estimated completion

#### 5. Findings Management

**Findings Document:** `docs/findings/findings.md`

PM tracks:
- Technical debt discovered
- Performance issues found
- Security concerns identified
- Architecture improvements needed
- Priority assignment for addressing findings

### PM Commands Reference

```bash
# When user asks for ANY work:
"Let me consult the project manager to assess this request."

# PM uses these agents:
# 1. For planning new features:
Task → architect-planning-design

# 2. For backend work:
Task → backend-architect-engineer

# 3. For frontend work:
Task → frontend-engineer

# 4. For QA:
Task → backend-qa-engineer OR frontend-qa-specialist

# 5. For database changes:
Task → database-architect

# 6. For infrastructure:
Task → sre-engineer

# 7. For security review:
Task → security-pentest-auditor
```

---

## Backend Engineering (BE)

### Core Architecture

#### Directory Structure
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
│   ├── Controllers/  # API controllers (separate from Filament)
│   ├── Middleware/   # Custom middleware
│   ├── Requests/     # Form request validation
│   └── Resources/    # API resources
├── Models/           # Eloquent models
├── Observers/        # Model observers
├── Providers/        # Service providers
└── Services/         # Business logic services
```

### Laravel Best Practices

#### 1. Service Layer Pattern
- Place complex business logic in **Service classes**, not controllers
- Services should be in `app/Services/{Domain}/`
- Use dependency injection for all dependencies

```php
// Good
class IncidentService
{
    public function __construct(
        private NotificationService $notifications,
        private MetricsCalculator $metrics
    ) {}

    public function createIncident(array $data): Incident
    {
        // Business logic here
    }
}
```

#### 2. Model Conventions
- Use **Observers** for model events (created, updated, deleted)
- Keep models thin - use Form Objects/Services for complex operations
- Define relationships with proper return types
- Use **Casts** for value objects

```php
class Incident extends Model
{
    protected $casts = [
        'incident_date' => 'datetime',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'financial_impact' => FinancialImpact::class,
    ];

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }
}
```

#### 3. Observer Pattern Usage
- Use for side effects: notifications, cache invalidation, metric updates
- **Never** block observers - use queues for heavy operations
- Keep observers focused on single responsibility

```php
class IncidentObserver
{
    public function created(Incident $incident): void
    {
        // Cache invalidation
        Cache::tags(['incidents'])->flush();

        // Queue notifications (don't block)
        if ($incident->pic) {
            dispatch(function () use ($incident) {
                $incident->pic->notify(new IncidentAssignedNotification($incident));
            });
        }
    }

    public function updated(Incident $incident): void
    {
        // Re-calculate metrics when status changes
        if ($incident->wasChanged('status')) {
            $incident->recalculateMetrics();
        }
    }
}
```

#### 4. API Development
- Use **API Resources** for consistent responses (`app/Http/Resources/`)
- Apply API versioning (`routes/api.php` → `/api/v1/...`)
- Use **Form Request Validation** classes
- Implement proper HTTP status codes
- Use `ApiResponser` trait for standardized responses

```php
class IncidentController extends Controller
{
    public function store(StoreIncidentRequest $request): JsonResponse
    {
        $incident = IncidentService::create($request->validated());
        return $this->successResponse(
            new IncidentApiResource($incident),
            'Incident created successfully',
            201
        );
    }
}
```

#### 5. Validation Standards
- Use **Form Request** classes for all non-trivial validation
- Define rules in `rules()` method
- Add custom error messages in `messages()` method
- Use `sometimes()` for conditional validation

```php
class StoreIncidentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'max:255'],
            'incident_date' => ['required', 'date', 'before_or_equal:today'],
            'severity' => ['required', new Enum(Severity::class)],
            'financial_impact.potential_loss' => ['nullable', 'numeric', 'min:0'],
            'pic_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
```

#### 6. Queue & Job Standards
- Implement **queued jobs** for time-consuming operations
- Use `--queue` flag in production
- Set proper `$tries` and `$timeout` properties
- Implement proper failure handling with `failed()` method

```php
class ExportIncidentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        private User $user,
        private array $filters
    ) {}

    public function handle(): void
    {
        // Export logic
    }

    public function failed(Throwable $exception): void
    {
        $this->user->notify(new ExportFailedNotification($exception));
    }
}
```

#### 7. Caching Strategy
- Use **tag-based caching** for cache invalidation
- Cache expensive queries and computed metrics
- Use cache keys with prefixes

```php
// Good - Tag-based caching
$incidents = Cache::tags(['incidents', 'user:'.$userId])
    ->remember("incidents:filter:{$cacheKey}", 3600, fn() =>
        Incident::filter($filters)->get()
    );

// Clear related caches
Cache::tags(['incidents'])->flush();
```

#### 8. Exception Handling
- Create custom exceptions in `app/Exceptions/`
- Use Laravel's exception handler for rendering
- Return proper HTTP status codes

```php
// app/Exceptions/IncidentNotFoundException.php
class IncidentNotFoundException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'Incident not found',
            'message' => $this->getMessage(),
        ], 404);
    }
}
```

### Filament Best Practices

#### 1. Resource Organization
- One resource per main entity
- Use **Relation Managers** for relationships
- Group fields in `Sections()` for complex forms
- Use `Tabs()` for alternative grouping

```php
class IncidentResource extends Resource
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Core Details')
                    ->schema([
                        TextInput::make('summary')->required(),
                        DatePicker::make('incident_date')->required(),
                        // ...
                    ]),
                Section::make('Financial Impact')
                    ->schema([
                        // Financial fields
                    ])->collapsible(),
            ]);
    }
}
```

#### 2. Query Modification Pattern
Use for filtering resources (e.g., Issues vs Incidents):

```php
class IssueResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('classification', 'Issue');
    }
}
```

#### 3. Custom Filters
- Create reusable filter classes in `app/Filament/Filters/`
- Implement both query and table filtering

```php
class QuickPeriodFilter extends Filter
{
    public static function getDefaultOptions(): array
    {
        return ['this_month', 'this_year'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        return match ($filters['period'] ?? null) {
            'this_week' => $query->whereBetween('incident_date', [
                now()->startOfWeek(),
                now()->endOfWeek(),
            ]),
            'this_month' => $query->whereMonth('incident_date', now()->month),
            default => $query,
        };
    }
}
```

#### 4. Widget Development
- Extend appropriate base class (`StatsWidget`, `ChartWidget`)
- Use proper caching in `getData()` method
- Implement `getTableRecords()` with sorting

```php
class RecentIncidents extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    protected function getTableRecords(): LengthAwarePaginator
    {
        return Incident::latest()
            ->with(['pic', 'incidentType'])
            ->paginate(10);
    }
}
```

#### 5. Action & Page Registration
- Register custom actions in `$form->actions`
- Use `app/Filament/Pages/` for standalone pages
- Use `HasActions` trait for reusable actions

### Code Style Standards

- Run **Laravel Pint** before committing: `./vendor/bin/pint`
- Follow **PSR-12** coding standard
- Use **PHP 8.2+** features: `readonly` properties, `match` expressions, constructor property promotion
- Max line length: 120 characters
- Use strict types (`declare(strict_types=1);`) in new files

---

## Frontend Engineering (FE)

### TailwindCSS Standards

#### 1. Configuration
- Custom configuration in `tailwind.config.js`
- Use `@source` directive for automatic scanning
- Custom theme colors defined in config

```javascript
export default {
  content: ['./resources/**/*.blade.php'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Instrument Sans', 'sans-serif'],
      },
      colors: {
        primary: { /* ... */ },
        danger: { /* ... */ },
      },
    },
  },
};
```

#### 2. Custom Styles
- Keep custom CSS minimal - prefer Tailwind utilities
- Use `@layer` directive for extending components
- Store custom styles in `resources/css/app.css`

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer components {
  .btn-primary {
    @apply bg-primary-600 text-white px-4 py-2 rounded hover:bg-primary-700;
  }
}
```

#### 3. Filament Theming
- Custom panel config in `AdminPanelProvider.php`
- Define custom brand colors
- Configure sidebar behavior

```php
Panel::make()
    ->brandLogo(asset('images/logo.png'))
    ->brandName('Technical Risk Dashboard')
    ->colors([
        'primary' => Color::Amber,
    ])
    ->sidebarCollapsibleOnDesktop();
```

### JavaScript Standards

#### 1. Asset Management
- Use Vite for all asset compilation
- Import dependencies via ES modules
- Use Laravel Vite plugin

```javascript
import { createInertiaApp } from '@inertiajs/inertia-vue3';
import '../css/app.css';
```

#### 2. Alpine.js Usage (if needed)
- Use for lightweight interactivity
- Keep logic in `x-data` attributes
- For complex state, use Livewire or create custom Filament widgets

### Performance Best Practices

- Enable **Vite HMR** in development
- Minimize CSS by purging unused Tailwind classes (automatic in production)
- Lazy load heavy JavaScript modules
- Use `vite build --minify` for production

---

## Quality Assurance (QA)

### Testing Strategy

#### 1. Test Organization
```
tests/
├── Unit/              # Isolated component tests
│   ├── Models/
│   ├── Services/
│   └── Helpers/
├── Feature/           # Integration tests
│   ├── Api/
│   ├── Web/
│   └── Console/
└── Pest/              # Pest tests (if using Pest)
```

#### 2. Unit Testing Standards
- Test all public methods in Services
- Mock external dependencies
- Use data providers for multiple scenarios
- Aim for 80%+ code coverage

```php
class IncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_incident_with_valid_data(): void
    {
        $service = new IncidentService(
            $this->mock(NotificationService::class)
        );

        $incident = $service->createIncident([
            'summary' => 'Test Incident',
            'severity' => Severity::P1,
        ]);

        $this->assertDatabaseHas('incidents', [
            'summary' => 'Test Incident',
        ]);
    }
}
```

#### 3. Feature Testing Standards
- Test API endpoints comprehensively
- Cover validation errors
- Test authorization/permissions
- Test happy path and error paths

```php
class IncidentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_incidents(): void
    {
        $response = $this->getJson('/api/v1/incidents');
        $response->assertUnauthorized();
    }

    public function test_can_create_incident(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/incidents', [
            'summary' => 'New Incident',
            'severity' => 'P1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.summary', 'New Incident');
    }
}
```

#### 4. Testing Checklist

**For New Features:**
- [ ] Unit tests for service methods
- [ ] Feature tests for API endpoints
- [ ] Validation tests for form requests
- [ ] Permission/authorization tests
- [ ] Observer event tests
- [ ] Edge case coverage

**For Bug Fixes:**
- [ ] Reproducible test case for bug
- [ ] Verify fix with test
- [ ] No regression in existing tests

### Manual Testing Standards

#### 1. Filament Resource Testing
- Test all form validations
- Verify relationship managers work
- Test filters and sorting
- Check create/edit/delete operations
- Verify export functionality

#### 2. Browser Testing Checklist
- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Mobile responsive (iOS/Android)

---

## Site Reliability Engineering (SRE)

### Docker Configuration

#### 1. Container Architecture
```yaml
# docker-compose.yml structure
services:
  app:     # PHP-FPM 8.2
  nginx:   # Web server
  mysql:   # MySQL 8.0
  redis:   # Redis cache/queue
  queue:   # Laravel queue worker
```

#### 2. Health Checks
Configure for all services:
```yaml
healthcheck:
  test: ["CMD", "php-fpm-healthcheck"]
  interval: 30s
  timeout: 10s
  retries: 3
```

#### 3. Resource Limits
Set appropriate limits:
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

### Monitoring & Logging

#### 1. Logging Standards
- Use **Laravel Pail** for log monitoring: `php artisan pail`
- Configure log levels per environment
- Use context for better log filtering

```php
Log::info('Incident created', [
    'incident_id' => $incident->id,
    'user_id' => auth()->id(),
]);
```

#### 2. Queue Monitoring
- Monitor queue depth: `php artisan queue:monitor`
- Set up failed job notifications
- Configure retry policies

```bash
# Monitor high-priority queues
php artisan queue:monitor high-priority,default --max=100
```

#### 3. Cache Management
- Monitor cache hit rates
- Set appropriate TTLs
- Tag-based cache invalidation

### Backup Strategy

#### 1. Database Backups
- Daily automated backups
- Retention: 30 days
- Off-site storage

#### 2. File Storage
- Document encryption at rest
- Secure file deletion policies
- Regular access audits

### Performance Optimization

#### 1. Database Query Optimization
- Use **Eager Loading** (`with()`) to prevent N+1 queries
- Add database indexes for commonly queried fields
- Use database query caching

```php
// Bad - N+1 query
$incidents = Incident::all();
foreach ($incidents as $incident) {
    echo $incident->pic->name; // Queries each time
}

// Good - Eager loading
$incidents = Incident::with('pic')->get();
```

#### 2. API Response Optimization
- Use pagination for large datasets
- Implement rate limiting
- Cache expensive computations

```php
// routes/api.php
Route::middleware('throttle:60,1')->group(function () {
    Route::apiResource('incidents', IncidentController::class);
});
```

---

## Security & Compliance

### Authentication & Authorization

#### 1. Role-Based Access Control
- Use **Spatie Laravel Permission**
- Define roles and permissions in database
- Use middleware for route protection

```php
// Check permission
$user->can('view incidents');

// Middleware
Route::middleware(['permission:delete incidents'])
    ->delete('/incidents/{id}');
```

#### 2. API Security
- Use **Laravel Sanctum** for API tokens
- Implement token expiration
- Scope tokens with abilities

```php
$token = $user->createToken('api-token', ['incidents:read']);
```

### Data Protection

#### 1. Document Encryption
- Store encryption keys securely
- Use Laravel's encryption for sensitive data
- Implement secure file deletion

#### 2. Audit Logging
- Use **OwenIt Auditing** for model changes
- Log all critical operations
- Regular audit log reviews

### Security Best Practices

- [ ] Validate all user input
- [ ] Use parameterized queries (Eloquent handles this)
- [ ] Escape output (Blade handles this)
- [ ] Implement CSRF protection
- [ ] Use HTTPS only in production
- [ ] Keep dependencies updated (`composer update`, `npm update`)
- [ ] Run security audits (`composer audit`, `npm audit`)

---

## Database Management

### Migration Standards

#### 1. Naming Conventions
- Use descriptive, snake_case names
- Timestamp prefix (auto-generated)
- Descriptive table creation: `create_incidents_table`

```bash
php artisan make:migration create_action_improvements_table
```

#### 2. Schema Design
- Use appropriate column types
- Set sensible defaults
- Add indexes for foreign keys and frequently queried columns
- Use `onDelete('cascade')` for relationships

```php
Schema::create('action_improvements', function (Blueprint $table) {
    $table->id();
    $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
    $table->string('action')->nullable();
    $table->text('description')->nullable();
    $table->date('due_date')->nullable();
    $table->boolean('is_completed')->default(false);
    $table->timestamps();

    $table->index(['incident_id', 'is_completed']);
    $table->index('due_date');
});
```

#### 3. Index Strategy
- Add indexes for:
  - Foreign keys
  - Frequently filtered columns
  - Date ranges (incident_date, created_at)
  - Composite indexes for multi-column queries

### Seeding Standards

- Use **Factory** classes for test data
- Separate `DatabaseSeeder` for development vs production
- Use seeders for reference data (labels, incident types)

---

## Testing Standards

### PHPUnit Configuration

- Use SQLite in-memory for fast tests
- Configure test environment in `phpunit.xml`
- Use `RefreshDatabase` trait for clean state

```xml
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

### Test Data Management

- Use **Factories** for test data generation
- Define realistic states in factories
- Use `seed()` for reference data

```php
User::factory()->create([
    'name' => 'Test User',
    'email' => 'test@example.com',
]);
```

### Continuous Integration

- Run tests on every PR
- Enforce code coverage thresholds
- Run security audits
- Verify code style compliance

---

## Continuous Improvement

### Documentation Structure

```
docs/
├── projects/
│   └── active-projects.md      # Track all ongoing work
├── bugs/
│   └── lesson-learned.md        # Bug RCA and prevention
├── findings/
│   └── findings.md              # Technical debt & findings
└── continuous-improvement.md    # Overall improvement initiatives
```

### Lesson Learned Process

**MANDATORY for EVERY BUG:**

1. **Document in `docs/bugs/lesson-learned.md`**
2. **Include:**
   - Bug description
   - Root Cause Analysis (5 Whys)
   - Impact assessment
   - Prevention strategy
   - Code/process changes made

#### Lesson Learned Template

```markdown
## [BUG-XXX] - Brief Bug Title

**Date:** YYYY-MM-DD
**Discovered By:** [Name]
**Severity:** [Critical/High/Medium/Low]

### Description
[Brief description of what happened]

### Root Cause Analysis (5 Whys)
1. Why did the bug occur?
   - Answer
2. Why did that happen?
   - Answer
3. Continue asking "why" until root cause is found

### Impact
- [ ] User facing?
- [ ] Data loss/corruption?
- [ ] Performance degradation?
- [ ] Security vulnerability?

### Prevention Strategy
1. **Process Change:**
   - [ ] Update SOP
   - [ ] Add validation
   - [ ] Add monitoring

2. **Code Change:**
   - [ ] Add unit test
   - [ ] Add integration test
   - [ ] Refactor code

3. **Documentation:**
   - [ ] Update CLAUDE.md
   - [ ] Add code comments

### Action Items
- [ ] Action item 1 - [Assigned To] - [Due Date]
- [ ] Action item 2 - [Assigned To] - [Due Date]

### Verification
- [ ] Test case added: `path/to/test.php`
- [ ] Code review completed
- [ ] Deployment verified
```

### Active Projects Tracking

**File:** `docs/projects/active-projects.md`

```markdown
# Active Projects

## Project Template

### [PROJ-XXX] Project Name
**Status:** [Backlog/In Progress/In Review/Done]
**Priority:** [P1/P2/P3/P4]
**PM:** [Name]
**Assigned Agents:** [List]
**Start Date:** YYYY-MM-DD
**Target Completion:** YYYY-MM-DD

#### Description
[Brief description of project goals]

#### Technical Approach
- [Architecture decisions]

#### Tasks
- [ ] Task 1 - [Agent] - [Status]
- [ ] Task 2 - [Agent] - [Status]

#### Dependencies
- Dependency 1
- Dependency 2

#### Blockers
- Blocker (if any)

#### Progress Updates
- YYYY-MM-DD: Update 1
- YYYY-MM-DD: Update 2
```

### Findings Tracking

**File:** `docs/findings/findings.md`

```markdown
# Technical Findings

## Format

### [FIND-XXX] Finding Title
**Date:** YYYY-MM-DD
**Category:** [Performance/Security/Architecture/Technical Debt]
**Severity:** [Critical/High/Medium/Low]
**Status:** [Open/In Progress/Resolved]

#### Description
[What was found]

#### Impact
[Why it matters]

#### Recommended Action
[What should be done]

#### Priority
[When should it be addressed]
```

### Continuous Improvement Initiatives

**File:** `docs/continuous-improvement.md`

Track:
- Process improvements
- Tool upgrades
- Training needs
- Best practice adoption
- Performance targets

---

## Quick Reference Commands

### Development
```bash
# Start all services
composer dev

# Run tests
php artisan test

# Code style fix
./vendor/bin/pint

# Clear caches
php artisan optimize:clear

# Generate API docs
php artisan scribe:generate
```

### Production
```bash
# Deploy
docker-compose down && docker-compose up -d --build

# Run migrations
php artisan migrate --force

# Clear and cache configs
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart queue
php artisan queue:restart
```

### Monitoring
```bash
# View logs
php artisan pail

# Check queue status
php artisan queue:monitor

# Check scheduled tasks
php artisan schedule:run
```

---

## Contact & Support

- **Documentation:** Keep CLAUDE.md updated with architectural decisions
- **API Documentation:** Auto-generated via Scribe at `/docs`
- **Project Management:** ALL requests go through PM first
- **Issue Tracking:** GitHub Issues for bugs and feature requests

---

## Change Log

### Version History
- Major version changes tracked via Git tags
- Maintain CHANGELOG.md for user-facing changes
- Document breaking changes in release notes

---

*This SOP is a living document. Update as the project evolves and new patterns emerge.*
