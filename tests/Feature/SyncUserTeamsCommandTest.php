<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * users:sync-teams (team-viewer role, owner 2026-11-20): DEFAULT mode — the
 * raw department string IS the team ("i want to put all of it not grouped"):
 * every distinct department becomes its own responsible_team category, 1:1,
 * full replace per user. --use-map switches to the unified config grouping.
 * Manual mapping (teams_manual) is skipped unless --force.
 */
class SyncUserTeamsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['team-map' => [
            'Security' => 'IT Sec',
            'Data' => 'Data',
        ]]);
    }

    public function test_department_mode_maps_each_department_to_its_own_team(): void
    {
        $sec = User::factory()->create(['department' => 'Security']);
        $data = User::factory()->create(['department' => 'Data']);

        $this->artisan('users:sync-teams')->assertSuccessful();

        $this->assertSame(['Security'], $sec->fresh()->teams()->pluck('name')->all(),
            'Default mode: the department string IS the team — no folding');
        $this->assertSame(['Data'], $data->fresh()->teams()->pluck('name')->all());
        $this->assertNotNull(Category::where('type', 'responsible_team')->where('name', 'Security')->first(),
            'Every distinct department becomes a category');
    }

    public function test_map_mode_folds_departments_via_config(): void
    {
        $sec = User::factory()->create(['department' => 'Security']);

        $this->artisan('users:sync-teams', ['--use-map' => true])->assertSuccessful();

        $this->assertSame(['IT Sec'], $sec->fresh()->teams()->pluck('name')->all());
    }

    public function test_dry_run_writes_nothing(): void
    {
        User::factory()->create(['department' => 'Security']);

        $this->artisan('users:sync-teams', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Category::where('type', 'responsible_team')->count());
        $this->assertSame(0, User::first()->teams()->count());
    }

    public function test_map_mode_reports_unmapped_departments_not_guesses(): void
    {
        User::factory()->create(['department' => 'Mystery Department']);

        $this->artisan('users:sync-teams', ['--use-map' => true])
            ->expectsOutputToContain('Mystery Department')
            ->assertSuccessful();

        $this->assertSame([], User::first()->teams()->pluck('name')->all(),
            'No mapping, no guess — the user stays unmapped');
    }

    public function test_empty_department_is_skipped_silently(): void
    {
        User::factory()->create(['department' => null]);
        User::factory()->create(['department' => '']);

        $this->artisan('users:sync-teams')
            ->doesntExpectOutputToContain('Unmapped departments')
            ->assertSuccessful();
    }

    public function test_re_run_replaces_stale_teams(): void
    {
        $user = User::factory()->create(['department' => 'Security']);
        $this->artisan('users:sync-teams')->assertSuccessful();
        $this->assertSame(['Security'], $user->fresh()->teams()->pluck('name')->all());

        $user->update(['department' => 'Data']);
        $this->artisan('users:sync-teams')->assertSuccessful();

        $this->assertSame(['Data'], $user->fresh()->teams()->pluck('name')->all(),
            'A department move must not leave the old team attached');
    }

    public function test_manual_mapping_is_skipped_unless_forced(): void
    {
        $user = User::factory()->create(['department' => 'Security', 'teams_manual' => true]);

        $this->artisan('users:sync-teams')->assertSuccessful();
        $this->assertSame([], $user->fresh()->teams()->pluck('name')->all(), 'Manual mapping is skipped');

        $this->artisan('users:sync-teams', ['--force' => true])->assertSuccessful();
        $this->assertSame(['Security'], $user->fresh()->teams()->pluck('name')->all(), '--force re-maps');
    }

    public function test_options_cache_is_flushed_after_new_categories(): void
    {
        Category::options('responsible_team'); // warm the cache
        $this->assertTrue(Cache::has('categories:responsible_team'));

        User::factory()->create(['department' => 'Security']);
        $this->artisan('users:sync-teams')->assertSuccessful();

        $this->assertFalse(Cache::has('categories:responsible_team'), 'Stale cached options must not serve form selects');
    }

    public function test_manual_mapped_users_create_no_category(): void
    {
        // The skip must run BEFORE firstOrCreate — otherwise every manual
        // mapping leaves a permanent orphan category its pivots never use.
        User::factory()->create(['department' => 'Security', 'teams_manual' => true]);

        $this->artisan('users:sync-teams')->assertSuccessful();

        $this->assertDatabaseMissing(Category::class, ['type' => 'responsible_team', 'name' => 'Security']);
    }

    public function test_dry_run_does_not_flush_the_options_cache(): void
    {
        Category::options('responsible_team'); // warm the cache
        $this->assertTrue(Cache::has('categories:responsible_team'));

        User::factory()->create(['department' => 'Security']);
        $this->artisan('users:sync-teams', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue(Cache::has('categories:responsible_team'), 'A dry run created nothing — it must not flush');
    }
}
