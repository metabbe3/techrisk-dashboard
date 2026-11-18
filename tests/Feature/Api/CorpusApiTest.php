<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /api/v1/corpus?path=… — the DingTalk agent's read path into the
 * incident memory corpus (index → open a page, LLM-Wiki style). The endpoint
 * is the ONLY thing exposing corpus files over HTTP, so traversal is
 * rejected server-side; the Go tool re-checks but the server never trusts it.
 */
class CorpusApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenWith(array $endpoints): string
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $token = $user->createToken('test', ['*']);
        PersonalAccessToken::find($token->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode($endpoints)])
            ->save();

        return $token->plainTextToken;
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/v1/corpus?path=index.md')->assertStatus(401);
    }

    public function test_token_without_corpus_endpoint_gets_403(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['incidents']))
            ->getJson('/api/v1/corpus?path=index.md')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_serves_a_corpus_page_inside_the_root(): void
    {
        Storage::disk('local')->put('markdown/corpus/index.md', "- [Topics](topics/index.md)\n");
        Storage::disk('local')->put('markdown/corpus/topics/payments.md', "# Patterns: Payments\ngateway timeouts (2026_IN_0001)\n");
        Storage::disk('local')->put('markdown/corpus/2040_IN_009/incident.md', "# Incident\n");

        $auth = ['Authorization', 'Bearer '.$this->tokenWith(['agent-corpus'])];

        $this->withHeader(...$auth)
            ->getJson('/api/v1/corpus?path=topics/payments.md')
            ->assertStatus(200)
            ->assertJsonPath('data.path', 'topics/payments.md')
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.content', "# Patterns: Payments\ngateway timeouts (2026_IN_0001)\n");

        $this->withHeader(...$auth)
            ->getJson('/api/v1/corpus?path=index.md')
            ->assertStatus(200)
            ->assertJsonPath('data.path', 'index.md');
    }

    public function test_unknown_page_gets_404(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['agent-corpus']))
            ->getJson('/api/v1/corpus?path=ghost.md')
            ->assertStatus(404);
    }

    public function test_traversal_and_non_markdown_are_rejected(): void
    {
        $auth = ['Authorization', 'Bearer '.$this->tokenWith(['agent-corpus'])];

        foreach (['..%2F.env', '.%2e%2e/secret.md', '%2Fetc%2Fpasswd', 'topics\\x.md', 'topics/payments.pdf', 'topics/payments.md%00.png'] as $bad) {
            $this->withHeader(...$auth)
                ->getJson("/api/v1/corpus?path={$bad}")
                ->assertStatus(422, "path {$bad} must be rejected");
        }

        Storage::disk('local')->assertMissing('markdown/corpus/secret.md');
    }

    public function test_oversized_page_is_truncated_with_a_flag(): void
    {
        Storage::disk('local')->put('markdown/corpus/big.md', str_repeat('x', 200_000));

        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['agent-corpus']))
            ->getJson('/api/v1/corpus?path=big.md')
            ->assertStatus(200)
            ->assertJsonPath('data.truncated', true);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['agent-corpus']))
            ->getJson('/api/v1/corpus?path=big.md')
            ->assertJsonPath('data.content', fn ($content) => mb_strlen((string) $content) <= 100_000);
    }
}
