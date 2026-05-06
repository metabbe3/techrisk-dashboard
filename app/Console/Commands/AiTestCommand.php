<?php

namespace App\Console\Commands;

use App\Services\Ai\AiTextService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class AiTestCommand extends Command
{
    protected $signature = 'ai:test';

    protected $description = 'Test AI service connectivity and configuration';

    public function handle(): int
    {
        $this->info('Checking AI service configuration...');
        $this->newLine();

        $baseUrl = config('ai.base_url');
        $apiKey = config('ai.api_key');
        $defaultModel = config('ai.default_model');
        $timeout = config('ai.timeout');

        $this->line('  Base URL:  '.($baseUrl ? "<fg=green>{$baseUrl}</>" : '<fg=red>NOT SET</>'));
        $this->line('  API Key:   '.($apiKey ? '<fg=green>'.substr($apiKey, 0, 8).'...</>' : '<fg=red>NOT SET</>'));
        $this->line('  Model:     '.($defaultModel ?? '<fg=red>NOT SET</>'));
        $this->line('  Timeout:   '.($timeout ?? 30).'s');
        $this->newLine();

        if (empty($baseUrl) || empty($apiKey)) {
            $this->error('Missing configuration. Run: php artisan ai:setup');

            return self::FAILURE;
        }

        $models = config('ai.models', []);
        $this->info('Available models: '.implode(', ', array_keys($models)));
        $this->newLine();

        // Test HTTP connection
        $this->info('Testing connection to AI proxy...');
        $url = rtrim($baseUrl, '/').'/chat/completions';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post($url, [
                    'model' => $defaultModel,
                    'messages' => [['role' => 'user', 'content' => 'Say OK']],
                    'max_tokens' => 5,
                ]);

            $status = $response->status();
            $this->line('  HTTP Status: '.$status);
            $this->line('  URL: '.$url);

            if ($response->successful()) {
                $body = $response->json();
                $content = $body['choices'][0]['message']['content'] ?? null;
                $usage = $body['usage'] ?? [];

                $this->newLine();
                $this->info('<fg=green>Connection successful!</>');
                $this->line('  Response: '.($content ?? 'empty'));
                $this->line('  Tokens: '.($usage['total_tokens'] ?? 'unknown'));

                return self::SUCCESS;
            }

            $this->error('API returned error:');
            $this->line('  '.$response->body());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Connection failed:');
            $this->line('  '.get_class($e).': '.$e->getMessage());
            $this->newLine();

            if (str_contains($e->getMessage(), 'SSL') || str_contains($e->getMessage(), 'certificate')) {
                $this->warn('SSL issue detected. Ensure ca-certificates is installed in your Docker image:');
                $this->line('  Dockerfile: RUN apt-get update && apt-get install -y ca-certificates');
            }

            if (str_contains($e->getMessage(), 'Connection refused') || str_contains($e->getMessage(), 'Could not resolve')) {
                $this->warn('Network issue detected. Check that the container can reach the API URL.');
                $this->line('  Test: docker exec <container> curl -I '.$baseUrl);
            }

            return self::FAILURE;
        }
    }
}
