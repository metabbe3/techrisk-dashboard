<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Category;
use App\Models\Incident;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\WeeklyDataService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /api/v1/weekly-report — the DingTalk agent's read over the weekly report.
 * ZERO new metric math: week counts (open/closed/total) come verbatim from
 * WeeklyDataService::getWeeklyData() — the same builder the Filament Weekly
 * Report page and its export show — so count eligibility (classification,
 * METRIC_ELIGIBLE severity, excludedFromCounts) is inherited by construction.
 * One-logic rule — the bot cannot drift from the page. Per-week incident rows
 * use the one list projection (IncidentListApiResource); the token user's year
 * access scopes rows for non-admin tokens (stats-endpoint precedent).
 */
class WeeklyReportApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Week numbers are the service's own Friday-to-Thursday map — tests place
     * seeds by calling getIsoWeeksInYear, never by re-deriving week math.
     */
    private function seedInWeek(int $year, int $weekNumber, array $attrs = []): Incident
    {
        $weeks = app(WeeklyDataService::class)->getIsoWeeksInYear($year);

        $date = $weeks[$weekNumber]['start']->copy()->startOfDay();

        // Completed rows must pass the incident closure gate (PROJ-011) — the
        // observer throws on Completed without stop-bleed time + root cause.
        $defaults = [
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P2->value,
            'incident_status' => IncidentStatus::Completed->value,
            'fund_status' => FundStatus::ConfirmedLoss->value,
            'incident_date' => $date,
            'stop_bleeding_at' => $date->copy()->addMinutes(30),
            'root_cause' => 'seeded',
        ];

        return Incident::factory()->create([...$defaults, ...$attrs]);
    }

    private function tokenWith(array $endpoints, ?User $user = null): string
    {
        $user ??= User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $token = $user->createToken('test', ['*']);
        PersonalAccessToken::find($token->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode($endpoints)])
            ->save();

        return $token->plainTextToken;
    }

    /**
     * Counting tests seed year 2025 (deterministic week windows) — a plain
     * user's UserAuditLogSetting defaults to the CURRENT year only, which
     * would legitimately empty every 2025 query. Admins pass the scope.
     */
    private function adminToken(array $endpoints): string
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $this->tokenWith($endpoints, $admin);
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/v1/weekly-report')->assertStatus(401);
    }

    public function test_token_without_weekly_report_endpoint_gets_403(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['incidents']))
            ->getJson('/api/v1/weekly-report')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_week_counts_inherit_the_service_eligibility(): void
    {
        $year = 2025;
        $this->seedInWeek($year, 10, ['incident_status' => IncidentStatus::Open->value, 'title' => 'Open one']);
        $this->seedInWeek($year, 10, ['title' => 'Closed one']);
        // Never counted: an Issue row, severity G, and an excluded fund status.
        $this->seedInWeek($year, 10, ['classification' => IncidentClassification::Issue->value]);
        $this->seedInWeek($year, 10, ['severity' => Severity::G->value]);
        $this->seedInWeek($year, 10, ['fund_status' => FundStatus::PotentialRecovery->value]);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->adminToken(['weekly-report']))
            ->getJson("/api/v1/weekly-report?year={$year}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'Success');

        $week = collect($res->json('data.weeks'))->firstWhere('week', 'W10');
        $this->assertSame(1, $week['incident_open'], 'only the Open row counts');
        $this->assertSame(1, $week['incident_closed'], 'only the Completed row counts');
        $this->assertSame(2, $week['total'], 'Issue / G / Potential-recovery rows never count');
        $this->assertCount(2, $week['incidents'], 'excluded rows are not listed either');
    }

    public function test_rows_use_the_compact_list_projection(): void
    {
        $this->seedInWeek(2025, 10, ['title' => 'Projection check']);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->adminToken(['weekly-report']))
            ->getJson('/api/v1/weekly-report?year=2025&weeks=53')
            ->assertStatus(200);

        $row = collect($res->json('data.weeks'))
            ->firstWhere('week', 'W10')['incidents'][0];

        foreach (['incident_no', 'incident_title', 'severity', 'incident_status', 'incident_date'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        foreach (['timeline', 'root_cause'] as $heavy) {
            $this->assertArrayNotHasKey($heavy, $row, "list rows stay compact (no {$heavy})");
        }
        $this->assertSame('Projection check', $row['incident_title']);
    }

    public function test_weeks_slice_returns_the_last_n_weeks(): void
    {
        $year = 2025;
        $all = app(WeeklyDataService::class)->getWeeklyData($year);
        // Seed in the LAST elapsed week so weeks=3 actually contains it.
        $this->seedInWeek($year, array_key_last(app(WeeklyDataService::class)->getIsoWeeksInYear($year)));

        $res = $this->withHeader('Authorization', 'Bearer '.$this->adminToken(['weekly-report']))
            ->getJson("/api/v1/weekly-report?year={$year}&weeks=3")
            ->assertStatus(200);

        $weeks = $res->json('data.weeks');
        $this->assertCount(3, $weeks);
        $this->assertSame(
            array_slice(array_column($all, 'week'), -3),
            array_column($weeks, 'week'),
            'the slice is the LAST three elapsed weeks, not the first'
        );
        // Summary sums only the returned slice.
        $this->assertSame(1, $res->json('data.summary.total'));
    }

    public function test_summary_sums_the_whole_year(): void
    {
        $year = 2025;
        $this->seedInWeek($year, 10, ['incident_status' => IncidentStatus::Open->value]);
        $this->seedInWeek($year, 20);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->adminToken(['weekly-report']))
            ->getJson("/api/v1/weekly-report?year={$year}")
            ->assertStatus(200);

        $weeks = $res->json('data.weeks');
        $this->assertSame(2, $res->json('data.summary.total'));
        $this->assertSame(1, $res->json('data.summary.open'));
        $this->assertSame(1, $res->json('data.summary.closed'));
        $this->assertSame(
            array_sum(array_column($weeks, 'total')),
            $res->json('data.summary.total'),
            'summary is the same sum the weekly export header computes'
        );
        $this->assertSame($year, $res->json('data.summary.year'));
    }

    public function test_non_admin_token_user_is_scoped_to_their_year_access(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $year = 2025;

        $team = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'IT Sec']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('access api');
        $viewer->assignRole('team-viewer');
        $viewer->teams()->sync([$team->id]);

        $this->seedInWeek($year, 10, ['responsible_team' => ['IT Sec'], 'title' => 'Own team']);
        $this->seedInWeek($year, 10, ['responsible_team' => ['SRE'], 'title' => 'Foreign team']);

        $viewerTok = $this->tokenWith(['weekly-report'], $viewer);
        $res = $this->withHeader('Authorization', 'Bearer '.$viewerTok)
            ->getJson("/api/v1/weekly-report?year={$year}&weeks=53")
            ->assertStatus(200);

        $week = collect($res->json('data.weeks'))->firstWhere('week', 'W10');
        $this->assertSame(1, $week['total'], 'team viewer sees only their own slice');
        $this->assertSame('Own team', $week['incidents'][0]['incident_title']);
    }

    // One authenticated user per test: Sanctum's guard instance caches the
    // resolved user across requests in-process, so a second getJson as a
    // different user still authenticates as the FIRST one.
    public function test_admin_token_user_sees_everything(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $year = 2025;

        $this->seedInWeek($year, 10, ['responsible_team' => ['IT Sec'], 'title' => 'Own team']);
        $this->seedInWeek($year, 10, ['responsible_team' => ['SRE'], 'title' => 'Foreign team']);

        $admin = User::factory()->create();
        $admin->givePermissionTo('access api');
        $admin->assignRole('admin');

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['weekly-report'], $admin))
            ->getJson("/api/v1/weekly-report?year={$year}&weeks=53")
            ->assertStatus(200);

        $week = collect($res->json('data.weeks'))->firstWhere('week', 'W10');
        $this->assertSame(2, $week['total'], 'scopeApplyUserYearAccess passes admins');
        $this->assertCount(2, $week['incidents']);
    }

    public function test_validation_rejects_out_of_range_params(): void
    {
        $auth = ['Authorization', 'Bearer '.$this->tokenWith(['weekly-report'])];

        $this->withHeader(...$auth)->getJson('/api/v1/weekly-report?year=2200')->assertStatus(422);
        $this->withHeader(...$auth)->getJson('/api/v1/weekly-report?weeks=0')->assertStatus(422);
        $this->withHeader(...$auth)->getJson('/api/v1/weekly-report?weeks=54')->assertStatus(422);
    }
}
