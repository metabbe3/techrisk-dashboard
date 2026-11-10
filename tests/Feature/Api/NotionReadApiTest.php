<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Notion\NotionMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NotionReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected const COLLECTION_URL = 'collection://2c72556e-44dd-81cf-8977-000bc43d6f86';

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $this->token = $user->createToken('test-token')->plainTextToken;
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function test_search_proxies_arguments_and_returns_text(): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->once()
            ->with('notion-search', ['query' => 'hbase'])
            ->andReturn(['structured' => null, 'text' => 'search result text', 'isError' => false]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/notion/search', ['query' => 'hbase']);

        $response->assertStatus(200)
            ->assertJsonPath('data.text', 'search result text')
            ->assertJsonPath('data.truncated', false);
    }

    public function test_search_forwards_an_explicit_page_size(): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->once()
            ->with('notion-search', ['query' => 'hbase', 'page_size' => 5])
            ->andReturn(['structured' => null, 'text' => 'search result text', 'isError' => false]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/notion/search', ['query' => 'hbase', 'page_size' => 5]);

        $response->assertStatus(200)
            ->assertJsonPath('data.text', 'search result text');
    }

    public function test_fetch_extracts_the_content_markdown_body(): void
    {
        $toolText = json_encode([
            'metadata' => ['type' => 'page'],
            'title' => 'PRD Payments',
            'text' => '<content># PRD Payments</content>',
        ]);

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->once()
            ->with('notion-fetch', ['id' => '4062556e44dd81c395b0c542f2599858'])
            ->andReturn(['structured' => null, 'text' => $toolText, 'isError' => false]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/notion/fetch', ['id' => '4062556e44dd81c395b0c542f2599858']);

        $response->assertStatus(200)
            ->assertJsonPath('data.content', '# PRD Payments')
            ->assertJsonPath('data.truncated', false);
    }

    public function test_oversized_page_body_is_clamped_to_48k_and_flagged(): void
    {
        $body = str_repeat('a', 60000);
        $toolText = json_encode(['text' => '<content>'.$body.'</content>']);

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->once()
            ->with('notion-fetch', ['id' => '4062556e44dd81c395b0c542f2599858'])
            ->andReturn(['structured' => null, 'text' => $toolText, 'isError' => false]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/notion/fetch', ['id' => '4062556e44dd81c395b0c542f2599858']);

        $response->assertStatus(200)
            ->assertJsonPath('data.truncated', true);
        $this->assertSame(48000, mb_strlen((string) $response->json('data.content')));
    }

    public function test_a_notion_tool_error_maps_to_502(): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->once()
            ->with('notion-search', ['query' => 'hbase'])
            ->andReturn(['structured' => null, 'text' => 'upstream tool failure', 'isError' => true]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/notion/search', ['query' => 'hbase']);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'Notion error: upstream tool failure');
    }

    public function test_a_disconnected_workspace_maps_to_503(): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->once()->andReturnNull();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/notion/search', ['query' => 'hbase']);

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Notion is not connected. Connect it on the Notion Settings page.');
    }

    public function test_query_resolves_the_database_once_and_surfaces_rows(): void
    {
        $databaseText = '<data-source url="'.self::COLLECTION_URL.'">PRD database</data-source>';
        $rowsText = json_encode(['results' => [
            ['url' => 'page-1', 'title' => 'Payment Gateway PRD', 'createdTime' => '2026-11-01 00:00:00Z'],
        ]]);

        $this->mock(NotionMcpClient::class)
            // Resolution happens ONCE across both query calls — the second
            // call hits the collection-url cache.
            ->shouldReceive('callToolDetailed')->once()
            ->with('notion-fetch', ['id' => 'db-prd-1'])
            ->andReturn(['structured' => null, 'text' => $databaseText, 'isError' => false])
            ->shouldReceive('callToolDetailed')->twice()
            ->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => ($args['data']['data_source_urls'][0] ?? null) === self::COLLECTION_URL
                    && ($args['data']['query'] ?? '') === 'SELECT * FROM "'.self::COLLECTION_URL.'" LIMIT 100'
            ))
            ->andReturn(['structured' => null, 'text' => $rowsText, 'isError' => false]);

        $payload = [
            'database' => 'db-prd-1',
            'query' => 'SELECT * FROM "'.self::COLLECTION_URL.'" LIMIT 100',
        ];

        $first = $this->withHeaders($this->headers())->postJson('/api/v1/notion/query', $payload);
        $second = $this->withHeaders($this->headers())->postJson('/api/v1/notion/query', $payload);

        foreach ([$first, $second] as $response) {
            $response->assertStatus(200)
                ->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.rows.0.title', 'Payment Gateway PRD');
        }
    }

    public function test_query_accepts_a_collection_url_without_resolving(): void
    {
        $rowsText = json_encode(['results' => [['url' => 'page-1', 'title' => 'TA Database']]]);

        // No notion-fetch expectation: an unexpected fetch call fails the mock.
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->once()
            ->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => ($args['data']['data_source_urls'][0] ?? null) === self::COLLECTION_URL
            ))
            ->andReturn(['structured' => null, 'text' => $rowsText, 'isError' => false]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/notion/query', [
                'database' => self::COLLECTION_URL,
                'query' => 'SELECT * FROM "'.self::COLLECTION_URL.'" LIMIT 1',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.rows.0.title', 'TA Database');
    }

    public function test_token_without_the_notion_endpoint_is_rejected(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $token = $user->createToken('restricted', ['*']);
        PersonalAccessToken::find($token->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode(['incidents'])])
            ->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/v1/notion/search', ['query' => 'hbase']);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_token_with_only_the_notion_endpoint_is_accepted(): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->once()->andReturnNull();

        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $token = $user->createToken('notion-only', ['*']);
        PersonalAccessToken::find($token->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode(['notion'])])
            ->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/v1/notion/search', ['query' => 'hbase']);

        $response->assertStatus(503); // passed the endpoint gate; Notion not connected in this test
    }

    public function test_a_too_short_search_query_is_rejected(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/notion/search', ['query' => 'h']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('query');
    }
}
