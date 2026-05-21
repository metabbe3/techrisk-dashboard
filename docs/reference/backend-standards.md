# Backend Engineering Standards (Reference)

Detailed code patterns and examples for backend development. Read this when implementing backend features.

---

## Service Layer Pattern

Place complex business logic in Service classes, not controllers. Services go in `app/Services/{Domain}/`. Use dependency injection.

```php
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

## Model Conventions

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

## Observer Pattern

- Use for side effects: notifications, cache invalidation, metric updates
- **Never** block observers - use queues for heavy operations
- Keep observers focused on single responsibility

```php
class IncidentObserver
{
    public function created(Incident $incident): void
    {
        Cache::tags(['incidents'])->flush();

        if ($incident->pic) {
            dispatch(function () use ($incident) {
                $incident->pic->notify(new IncidentAssignedNotification($incident));
            });
        }
    }

    public function updated(Incident $incident): void
    {
        if ($incident->wasChanged('status')) {
            $incident->recalculateMetrics();
        }
    }
}
```

## API Development

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

## Validation (Form Requests)

Use **Form Request** classes for all non-trivial validation.

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

## Queue & Job Standards

- Implement **queued jobs** for time-consuming operations
- Set proper `$tries` and `$timeout` properties
- Implement `failed()` method for failure handling

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

## Caching Strategy

Use **tag-based caching** for cache invalidation. Cache expensive queries and computed metrics.

```php
// Tag-based caching
$incidents = Cache::tags(['incidents', 'user:'.$userId])
    ->remember("incidents:filter:{$cacheKey}", 3600, fn() =>
        Incident::filter($filters)->get()
    );

// Clear related caches
Cache::tags(['incidents'])->flush();
```

## Exception Handling

Create custom exceptions in `app/Exceptions/`. Use Laravel's exception handler for rendering.

```php
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

---

## Filament Best Practices

### Resource Organization

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
                    ]),
                Section::make('Financial Impact')
                    ->schema([
                        // Financial fields
                    ])->collapsible(),
            ]);
    }
}
```

### Query Modification Pattern

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

### Custom Filters

Create reusable filter classes in `app/Filament/Filters/`:

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

### Widget Development

- Extend appropriate base class (`StatsWidget`, `ChartWidget`)
- Use proper caching in `getData()` method

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

---

## Code Style

- Run **Laravel Pint** before committing: `./vendor/bin/pint`
- Follow **PSR-12** coding standard
- Use **PHP 8.2+** features: `readonly`, `match`, constructor property promotion
- Max line length: 120 characters
- Use strict types (`declare(strict_types=1);`) in new files
