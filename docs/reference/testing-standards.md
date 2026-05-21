# Testing Standards (Reference)

Detailed testing patterns, examples, and checklists. Read this when writing tests.

---

## Test Organization

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

## Unit Testing

Test all public methods in Services. Mock external dependencies. Use data providers for multiple scenarios. Aim for 80%+ code coverage.

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

## Feature Testing

Test API endpoints comprehensively. Cover validation errors, authorization/permissions, happy path and error paths.

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

## PHPUnit Configuration

Use SQLite in-memory for fast tests. Configure in `phpunit.xml`:

```xml
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

## Test Data Management

Use **Factories** for test data generation. Define realistic states in factories. Use `seed()` for reference data.

```php
User::factory()->create([
    'name' => 'Test User',
    'email' => 'test@example.com',
]);
```

## Testing Checklists

### For New Features
- [ ] Unit tests for service methods
- [ ] Feature tests for API endpoints
- [ ] Validation tests for form requests
- [ ] Permission/authorization tests
- [ ] Observer event tests
- [ ] Edge case coverage

### For Bug Fixes
- [ ] Reproducible test case for bug
- [ ] Verify fix with test
- [ ] No regression in existing tests

### Filament Resource Testing
- [ ] Test all form validations
- [ ] Verify relationship managers work
- [ ] Test filters and sorting
- [ ] Check create/edit/delete operations
- [ ] Verify export functionality

### Browser Testing
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Mobile responsive (iOS/Android)

## Continuous Integration

- Run tests on every PR
- Enforce code coverage thresholds
- Run security audits
- Verify code style compliance
