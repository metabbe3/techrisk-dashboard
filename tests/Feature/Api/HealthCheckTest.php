<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'timestamp']);
    }

    public function test_health_check_does_not_require_auth(): void
    {
        // No Sanctum::actingAs -- completely unauthenticated
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
    }
}
