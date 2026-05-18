<?php

namespace App\Console\Commands;

use App\Services\Skills\SkillImportService;
use Illuminate\Console\Command;

class ImportSecuritySkillsCommand extends Command
{
    protected $signature = 'skills:import-security
                            {--path= : Local path to the SecuritySkills repository}
                            {--repo=https://github.com/UnitOneAI/SecuritySkills : GitHub repository URL}';

    protected $description = 'Import security skills from UnitOneAI/SecuritySkills repository';

    public function handle(SkillImportService $importService): int
    {
        $path = $this->option('path');

        if (! $path) {
            $path = $this->cloneRepository($this->option('repo'));

            if (! $path) {
                return self::FAILURE;
            }
        }

        if (! is_dir($path)) {
            $this->error("Directory not found: {$path}");

            return self::FAILURE;
        }

        $this->info('Importing security skills...');

        $result = $importService->importFromRepository($path);

        if (isset($result['error'])) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Created: {$result['created']}");
        $this->info("Updated: {$result['updated']}");
        $this->info("Skipped: {$result['skipped']}");
        $this->newLine();
        $this->info('Total skills in library: '.\App\Models\Skill::count());

        return self::SUCCESS;
    }

    protected function cloneRepository(string $repoUrl): ?string
    {
        $tempDir = sys_get_temp_dir().'/security-skills-'.uniqid();

        $this->info("Cloning {$repoUrl}...");

        $exitCode = $this->execClone("git clone --depth 1 {$repoUrl} {$tempDir}");

        if ($exitCode !== 0) {
            $this->error('Failed to clone repository. Use --path to specify a local copy.');

            return null;
        }

        register_shutdown_function(function () use ($tempDir) {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        });

        return $tempDir;
    }

    protected function execClone(string $command): int
    {
        $output = '';
        $exitCode = null;

        exec("{$command} 2>&1", $output, $exitCode);

        return $exitCode ?? 1;
    }
}
