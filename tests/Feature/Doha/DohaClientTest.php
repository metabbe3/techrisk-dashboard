<?php

declare(strict_types=1);

namespace Tests\Feature\Doha;

use App\Models\Setting;
use App\Services\Doha\DohaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DohaClientTest extends TestCase
{
    use RefreshDatabase;

    private DohaClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new DohaClient;
        $this->givenDohaToken();
        // Backoff sleeps (1s, 5s) must not slow the suite.
        config(['doha.retry_backoff' => [0, 0]]);
    }

    private function givenDohaToken(string $token = 'test-token'): void
    {
        Setting::set(DohaClient::TOKEN_KEY, Crypt::encryptString($token));
    }

    public function test_sends_bearer_token_and_query_params(): void
    {
        Http::fake(['doha.dana.id/*' => Http::response([])]);

        $this->client->incidents('2026-11-24 01:30:00', 500, 200);

        Http::assertSent(function ($request) {
            return parse_url($request->url(), PHP_URL_HOST) === 'doha.dana.id'
                && parse_url($request->url(), PHP_URL_PATH) === '/api/incidents'
                && $request->header('Authorization') === ['Bearer test-token']
                && $request['limit'] === 200
                && $request['offset'] === 500
                && $request['updatedSince'] === '2026-11-24 01:30:00';
        });
    }

    public function test_omits_updated_since_when_no_marker(): void
    {
        Http::fake(['doha.dana.id/*' => Http::response([])]);

        $this->client->incidents(null);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'updatedSince'));
    }

    public function test_returns_rows_on_200(): void
    {
        Http::fake(['doha.dana.id/*' => Http::response([
            ['id' => (string) Str::uuid(), 'ref' => 'INC-0050'],
        ])]);

        $result = $this->client->incidents();

        $this->assertNull($result['error']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('INC-0050', $result['rows'][0]['ref']);
    }

    public function test_4xx_is_never_retried(): void
    {
        Http::fake([
            'doha.dana.id/*' => Http::sequence()
                ->push(['error' => 'nope'], 401)
                ->push(['error' => 'nope'], 403)
                ->push(['error' => 'Incident not found'], 404),
        ]);

        foreach ([401, 403, 404] as $index => $status) {
            $result = $this->client->incidents();

            $this->assertSame($status, $result['status'], "status $status surfaced");
            $this->assertSame([], $result['rows']);
            $this->assertStringContainsString('HTTP '.$status, $result['error']);
        }

        Http::assertSentCount(3);
    }

    public function test_5xx_is_retried_until_success(): void
    {
        Http::fake([
            'doha.dana.id/*' => Http::sequence()
                ->push(['error' => 'boom'], 500)
                ->push(['error' => 'boom'], 500)
                ->push([['id' => (string) Str::uuid(), 'ref' => 'INC-0051']]),
        ]);

        $result = $this->client->incidents();

        $this->assertNull($result['error']);
        $this->assertCount(1, $result['rows']);
        Http::assertSentCount(3);
    }

    public function test_5xx_gives_up_after_three_attempts(): void
    {
        Http::fake(['doha.dana.id/*' => Http::response(['error' => 'boom'], 500)]);

        $result = $this->client->incidents();

        $this->assertSame(500, $result['status']);
        $this->assertSame([], $result['rows']);
        Http::assertSentCount(3);
    }

    public function test_error_body_is_truncated(): void
    {
        Http::fake(['doha.dana.id/*' => Http::response(str_repeat('x', 2000), 502)]);

        $result = $this->client->incidents();

        $this->assertLessThanOrEqual(600, strlen((string) $result['error']));
    }

    public function test_reports_unconfigured_without_token(): void
    {
        Setting::set(DohaClient::TOKEN_KEY, null);

        $this->assertFalse($this->client->configured());
        $this->assertNull($this->client->token());
    }

    public function test_reports_configured_with_token(): void
    {
        $this->assertTrue($this->client->configured());
        $this->assertSame('test-token', $this->client->token());
    }

    public function test_deleted_endpoint_sends_since(): void
    {
        Http::fake(['doha.dana.id/*' => Http::response([])]);

        $this->client->deleted('2026-11-24 01:30:00');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/incidents/deleted')
                && $request['since'] === '2026-11-24 01:30:00';
        });
    }
}
