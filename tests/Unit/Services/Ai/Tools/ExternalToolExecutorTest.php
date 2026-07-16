<?php

namespace Tests\Unit\Services\Ai\Tools;

use App\Models\AgentTool;
use App\Services\Ai\Tools\ExternalToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    private function httpTool(string $url, ?array $allowedHosts = null): AgentTool
    {
        $config = ['url' => $url, 'method' => 'GET'];
        if ($allowedHosts !== null) {
            $config['allowed_hosts'] = $allowedHosts;
        }

        return new AgentTool([
            'name' => 'test_tool',
            'display_name' => 'Test Tool',
            'executor_type' => 'http',
            'http_config' => $config,
        ]);
    }

    /**
     * @dataProvider blockedTargets
     */
    public function test_blocks_ssrf_targets(string $url): void
    {
        Http::fake();

        $result = (new ExternalToolExecutor)->execute($this->httpTool($url), []);

        $this->assertStringContainsString('blocked', $result);
        Http::assertNothingSent();
    }

    public static function blockedTargets(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'aws metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private range' => ['http://10.0.0.1/'],
            'bad scheme' => ['file:///etc/passwd'],
        ];
    }

    public function test_blocks_host_outside_allowlist(): void
    {
        Http::fake();

        $result = (new ExternalToolExecutor)->execute(
            $this->httpTool('http://evil.example.com/path', ['api.trusted.example']),
            []
        );

        $this->assertStringContainsString('blocked', $result);
        Http::assertNothingSent();
    }
}
