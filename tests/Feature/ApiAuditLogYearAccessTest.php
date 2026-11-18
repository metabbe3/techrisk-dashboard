<?php

namespace Tests\Feature;

use App\Filament\Resources\ApiAuditLogResource;
use App\Models\ApiAuditLog;
use App\Models\User;
use App\Models\UserAuditLogSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiAuditLogYearAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_year_allowed_years_see_every_granted_year(): void
    {
        Role::firstOrCreate(['name' => 'user']);

        $restricted = User::factory()->create();
        $restricted->assignRole('user');
        $this->actingAs($restricted);
        $settings = UserAuditLogSetting::create([
            'user_id' => $restricted->id,
            'allowed_years' => ['2025', '2026'],
            'can_view_all_logs' => false,
        ]);

        foreach ([2025, 2026, 2024] as $year) {
            ApiAuditLog::create([
                'request_timestamp' => "{$year}-06-01 10:00:00",
                'method' => 'GET',
                'endpoint' => '/api/v1/test',
                'user_id' => $restricted->id,
                'ip_address' => '127.0.0.1',
                'response_timestamp' => "{$year}-06-01 10:00:01",
                'response_status' => 200,
                'response_time_ms' => 5,
                'environment' => 'testing',
            ]);
        }

        // Same whereYear(col, array) head-flatten as the Incident scope: a
        // multi-year grant must see every granted year, not only the first.
        $apply = new ReflectionMethod(ApiAuditLogResource::class, 'applyAccessControl');
        $years = $apply->invoke(null, ApiAuditLog::query(), $settings)
            ->get()
            ->map(fn (ApiAuditLog $log) => $log->request_timestamp->format('Y'))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['2025', '2026'], $years);
    }

    public function test_admin_defaults_are_unrestricted(): void
    {
        Role::firstOrCreate(['name' => 'admin']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        // The real table() flow passes forUser(auth()->user()) — admins default
        // to can_view_all_logs = true, so no year restriction applies at all.
        $settings = UserAuditLogSetting::forUser($admin);
        $this->assertTrue($settings->can_view_all_logs);

        foreach ([2025, 2026] as $year) {
            ApiAuditLog::create([
                'request_timestamp' => "{$year}-06-01 10:00:00",
                'method' => 'GET',
                'endpoint' => '/api/v1/test',
                'user_id' => $admin->id,
                'ip_address' => '127.0.0.1',
                'response_timestamp' => "{$year}-06-01 10:00:01",
                'response_status' => 200,
                'response_time_ms' => 5,
                'environment' => 'testing',
            ]);
        }

        $apply = new ReflectionMethod(ApiAuditLogResource::class, 'applyAccessControl');
        $this->assertCount(2, $apply->invoke(null, ApiAuditLog::query(), $settings)->get());
    }
}
