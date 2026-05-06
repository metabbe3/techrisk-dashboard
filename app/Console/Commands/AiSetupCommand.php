<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class AiSetupCommand extends Command
{
    protected $signature = 'ai:setup';

    protected $description = 'Configure AI service credentials in .env';

    public function handle(): int
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->error('.env file not found.');

            return self::FAILURE;
        }

        $env = file_get_contents($envPath);
        $changed = false;

        if ($this->missingOrEmpty($env, 'AI_API_KEY')) {
            $key = password('Enter AI API Key', required: true);
            $env = $this->setEnvVar($env, 'AI_API_KEY', $key);
            $changed = true;
        } else {
            $this->line('  <fg=green>AI_API_KEY</> already set.');
        }

        if ($this->missingOrEmpty($env, 'AI_API_BASE_URL')) {
            $url = text('Enter AI API Base URL', hint: 'e.g. https://gateway.ai.dana.id', required: true);
            $env = $this->setEnvVar($env, 'AI_API_BASE_URL', $url);
            $changed = true;
        } else {
            $this->line('  <fg=green>AI_API_BASE_URL</> already set.');
        }

        if ($this->missingOrEmpty($env, 'AI_API_DEFAULT_MODEL')) {
            $model = select('Select default model', [
                'SMART-MODEL' => 'Smart Model (recommended)',
                'FAST-MODEL' => 'Fast Model',
                'REASONING-MODEL' => 'Reasoning Model',
            ]);
            $env = $this->setEnvVar($env, 'AI_API_DEFAULT_MODEL', $model);
            $changed = true;
        } else {
            $this->line('  <fg=green>AI_API_DEFAULT_MODEL</> already set.');
        }

        if ($changed) {
            file_put_contents($envPath, $env);
            $this->newLine();
            $this->info('AI configuration saved to .env');

            if (file_exists(base_path('bootstrap/cache/config.php'))) {
                $this->call('config:cache');
                $this->info('Config cache refreshed.');
            }
        } else {
            $this->info('All AI settings already configured. Nothing to do.');
        }

        return self::SUCCESS;
    }

    private function missingOrEmpty(string $env, string $key): bool
    {
        if (! preg_match("/^{$key}=(.*)$/m", $env, $m)) {
            return true;
        }

        return trim($m[1]) === '';
    }

    private function setEnvVar(string $env, string $key, string $value): string
    {
        $line = "{$key}={$value}";

        if (preg_match("/^{$key}=/m", $env)) {
            return preg_replace("/^{$key}=.*/m", $line, $env);
        }

        if (str_ends_with($env, "\n")) {
            return $env.$line."\n";
        }

        return $env."\n".$line."\n";
    }
}
