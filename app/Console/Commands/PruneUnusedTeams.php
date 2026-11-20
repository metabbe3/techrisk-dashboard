<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Incident;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-20 ("can you clean it on prod db?"): after switching the
 * team registry to department = team, the old grouped team categories
 * linger. This deletes responsible_team categories that are FULLY unused —
 * zero users mapped AND zero incidents referencing the name in
 * `incidents.responsible_team` JSON. Anything an incident still references
 * is KEPT (deleting it would orphan the incident's tag: the form select and
 * filters read Category::options) and reported with its counts.
 * Deliberately dry-run-first: --confirm does the deleting.
 */
class PruneUnusedTeams extends Command
{
    protected $signature = 'teams:prune
        {--dry-run : List what would be deleted without writing}
        {--confirm : Required to actually delete}';

    protected $description = 'Delete responsible-team categories with no users and no incident references (dry-run first; --confirm deletes)';

    public function handle(): int
    {
        $removable = [];
        $kept = [];

        foreach (Category::where('type', Category::TYPE_RESPONSIBLE_TEAM)->orderBy('name')->get() as $category) {
            $users = DB::table('category_user')->where('category_id', $category->id)->count();
            $incidents = Incident::whereJsonContains('responsible_team', $category->name)->count();

            if ($users === 0 && $incidents === 0) {
                $removable[$category->id] = $category->name;
            } else {
                $kept[] = sprintf('%s (users: %d, incidents: %d)', $category->name, $users, $incidents);
            }
        }

        if ($removable === []) {
            $this->info('Nothing to prune — every responsible-team category is mapped to users or referenced by incidents.');

            return self::SUCCESS;
        }

        foreach ($removable as $name) {
            $this->line('  would delete: '.$name);
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('DRY RUN — %d category(ies) removable. Re-run with --confirm to delete.', count($removable)));

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->error(sprintf('%d category(ies) removable. Re-run with --confirm to delete them.', count($removable)));

            return self::FAILURE;
        }

        Category::whereIn('id', array_keys($removable))->delete();
        Cache::forget('categories:'.Category::TYPE_RESPONSIBLE_TEAM);

        $this->info(sprintf('Deleted %d unused responsible-team category(ies); options cache flushed.', count($removable)));

        if ($kept !== []) {
            $this->line('Kept (in use):');
            foreach ($kept as $line) {
                $this->line('  '.$line);
            }
        }

        return self::SUCCESS;
    }
}
