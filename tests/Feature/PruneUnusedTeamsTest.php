<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * teams:prune (owner 2026-11-20): deletes responsible-team categories that
 * have NO users and NO incident references — the cleanup after switching
 * the registry to department = team. Categories an incident still
 * references are KEPT (the incident form select + filters read
 * Category::options; deleting would orphan the tag). Dry-run first,
 * --confirm deletes.
 */
class PruneUnusedTeamsTest extends TestCase
{
    use RefreshDatabase;

    private function team(string $name): Category
    {
        return Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => $name]);
    }

    public function test_deletes_unused_and_keeps_referenced_categories(): void
    {
        $unused = $this->team('People Ops');           // invented, nothing points at it
        $withUser = $this->team('Technical Risk');
        User::factory()->create()->teams()->attach($withUser->id);
        $referenced = $this->team('IT Sec');
        Incident::factory()->create(['classification' => 'Incident', 'responsible_team' => ['IT Sec']]);

        $this->artisan('teams:prune', ['--confirm' => true])->assertSuccessful();

        $this->assertNull(Category::find($unused->id), 'Fully unused category is deleted');
        $this->assertNotNull(Category::find($withUser->id), 'User-mapped category survives');
        $this->assertNotNull(Category::find($referenced->id), 'Incident-referenced category survives');
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $unused = $this->team('People Ops');

        $this->artisan('teams:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(Category::find($unused->id));
    }

    public function test_without_confirm_refuses_to_delete(): void
    {
        $this->team('People Ops');

        $this->artisan('teams:prune')->assertFailed();

        $this->assertSame(1, Category::where('type', 'responsible_team')->count(), 'No silent deletes');
    }

    public function test_flushes_the_options_cache(): void
    {
        $this->team('People Ops');
        Category::options('responsible_team');
        $this->assertTrue(Cache::has('categories:responsible_team'));

        $this->artisan('teams:prune', ['--confirm' => true])->assertSuccessful();

        $this->assertFalse(Cache::has('categories:responsible_team'));
    }
}
