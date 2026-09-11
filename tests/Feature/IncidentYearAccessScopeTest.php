<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use App\Models\UserAuditLogSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IncidentYearAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_year_access_scope_limits_non_admin_and_skips_admin_or_guest(): void
    {
        Role::firstOrCreate(['name' => 'user']);
        Role::firstOrCreate(['name' => 'admin']);

        $restricted = User::factory()->create();
        $restricted->assignRole('user');
        UserAuditLogSetting::create([
            'user_id' => $restricted->id,
            'allowed_years' => ['2026'],
            'can_view_all_logs' => false,
        ]);

        Incident::factory()->create(['classification' => 'Issue', 'incident_date' => '2025-05-01 10:00:00']);
        Incident::factory()->create(['classification' => 'Issue', 'incident_date' => '2026-05-01 10:00:00']);

        // Non-admin only sees their allowed year…
        $this->assertCount(1, Incident::query()->applyUserYearAccess($restricted)->get());
        $this->assertSame(
            '2026-05-01',
            Incident::query()->applyUserYearAccess($restricted)->first()->incident_date->format('Y-m-d')
        );

        // …admin and guest stay unscoped (Resource pages run authenticated,
        // but the scope must not break job/console callers passing null).
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->assertCount(2, Incident::query()->applyUserYearAccess($admin)->get());
        $this->assertCount(2, Incident::query()->applyUserYearAccess(null)->get());
    }
}
