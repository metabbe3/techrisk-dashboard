<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Team-viewer role (owner 2026-11-20): map users.department onto the
 * category_user pivot. DEFAULT (owner decision 2026-11-20, "i want to put
 * all of it not grouped"): the raw department string IS the team — every
 * distinct department becomes its own responsible_team category, 1:1, no
 * merging. --use-map switches to the unified mapping in
 * config/team-map.php if the grouping model is ever wanted back.
 *
 * The follow-up to users:sync-directory (which refreshes departments) —
 * not scheduled, run it by hand after each directory sync.
 *
 * Semantics: FULL REPLACE per mapped user (stale teams from a department
 * move are corrected on the next run). Admins who mapped a user by hand in
 * User Management set teams_manual — those are skipped (--force re-maps).
 * In map mode every unmapped non-empty department is REPORTED with user
 * counts so HR renames are never silently dropped (departments mode cannot
 * be unmapped — the department itself is the team).
 */
class SyncUserTeams extends Command
{
    protected $signature = 'users:sync-teams
        {--dry-run : Report mapping coverage without writing}
        {--force : Re-map users whose team mapping is flagged manual}
        {--use-map : Map via config/team-map.php (unified teams) instead of the default 1:1 department = team}';

    protected $description = 'Map users to responsible-team categories from their Entra department — default: each department IS a team (1:1); --use-map for the unified config grouping';

    public function handle(): int
    {
        $useMap = (bool) $this->option('use-map');
        $map = [];

        if ($useMap) {
            $map = config('team-map');

            if (! is_array($map) || $map === []) {
                $this->error('config/team-map.php is empty — nothing to map.');

                return self::FAILURE;
            }

            // Case-insensitive lookup: Entra departments drift in casing
            // ("Security" vs "it security") — same team either way.
            $map = array_change_key_case($map, CASE_LOWER);
        }

        $users = User::query()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->orderBy('email')
            ->get(['id', 'department', 'teams_manual']);

        $mapped = 0;
        $changed = 0;
        $skippedManual = 0;
        $unmapped = []; // department => user count
        $dryRun = (bool) $this->option('dry-run');

        foreach ($users as $user) {
            // Default: the department string IS the team (1:1) — every
            // distinct department becomes a category. --use-map: unified.
            $teamName = $useMap
                ? ($map[strtolower(trim((string) $user->department))] ?? null)
                : trim((string) $user->department);

            if ($teamName === null || $teamName === '') {
                $unmapped[trim((string) $user->department)] = ($unmapped[trim((string) $user->department)] ?? 0) + 1;

                continue;
            }

            // Manual-mapped users are skipped BEFORE any category is created —
            // firstOrCreate-ing their department first would leave a permanent
            // orphan category their pivots never use (--force re-maps them).
            if ($user->teams_manual && ! $this->option('force')) {
                $skippedManual++;

                continue;
            }

            // Dry-run reports coverage only — it must not create categories
            // or touch pivots.
            $categoryId = $dryRun
                ? Category::where('type', Category::TYPE_RESPONSIBLE_TEAM)->where('name', $teamName)->value('id')
                : Category::firstOrCreate([
                    'type' => Category::TYPE_RESPONSIBLE_TEAM,
                    'name' => $teamName,
                ])->id;

            $mapped++;

            if ($dryRun) {
                continue;
            }

            if ($user->teams()->allRelatedIds()->all() === [$categoryId]) {
                continue; // already correct — sync() would still churn the pivot
            }

            $user->teams()->sync([$categoryId]);
            $changed++;
        }

        // firstOrCreate may have created new options — the cached
        // name→name map (form selects, filters) must not serve stale teams.
        // Dry-run created nothing, so it must not flush either.
        if (! $dryRun) {
            Cache::forget('categories:'.Category::TYPE_RESPONSIBLE_TEAM);
        }

        if ($unmapped !== []) {
            arsort($unmapped);
            $this->warn('Unmapped departments (add them to config/team-map.php, then re-run):');
            foreach ($unmapped as $department => $count) {
                $this->line(sprintf('  %-60s %4d user(s)', $department, $count));
            }
        }

        $this->info(sprintf(
            '%s%d users matched a team: %d mapped%s, %d manual-mapping skipped%s, %d distinct department(s) unmapped.',
            $this->option('dry-run') ? 'DRY RUN — ' : '',
            count($users),
            $mapped,
            $changed > 0 ? " ({$changed} pivot rows written)" : '',
            $skippedManual,
            $this->option('force') ? ' (--force re-mapped them)' : '',
            count($unmapped),
        ));

        return self::SUCCESS;
    }
}
