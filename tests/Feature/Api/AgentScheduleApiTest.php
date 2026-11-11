<?php

namespace Tests\Feature\Api;

use App\Enums\AiAgentEngine;
use App\Enums\AiAgentFrequency;
use App\Models\AiAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AgentScheduleApiTest extends TestCase
{
    use RefreshDatabase;

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

    private function storeReminder(array $overrides = [])
    {
        return $this->withHeaders($this->headers())
            ->postJson('/api/v1/agent-schedules', array_merge([
                'conversation_id' => 'cid-1',
                'conversation_type' => '1',
                'staff_id' => 'staff-1',
                'nick' => 'Andi',
                'mode' => 'message',
                'text' => 'standup reminder',
                'cron' => '5 9 * * *',
            ], $overrides));
    }

    public function test_store_creates_cron_reminder_with_next_short_no(): void
    {
        $response = $this->storeReminder();

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Reminder saved.')
            ->assertJsonPath('data.no', 1)
            ->assertJsonPath('data.mode', 'message')
            ->assertJsonPath('data.text', 'standup reminder')
            ->assertJsonPath('data.cron', '5 9 * * *');

        $this->assertNotNull($response->json('data.next_run_at'));

        $agent = AiAgent::where('dingtalk_conversation_id', 'cid-1')->first();
        $this->assertSame(AiAgentEngine::Static, $agent->engine);
        $this->assertSame(AiAgentFrequency::Cron, $agent->frequency);
        $this->assertSame(1, $agent->short_no);
        $this->assertSame('staff-1', $agent->dingtalk_staff_id);
        $this->assertSame('1', $agent->dingtalk_conversation_type);
        $this->assertTrue($agent->enabled);
        $this->assertSame('standup reminder', $agent->instructions);
        $this->assertStringStartsWith('Reminder: ', $agent->name);
    }

    public function test_store_task_mode_maps_to_go_engine_one_shot(): void
    {
        $response = $this->storeReminder([
            'mode' => 'task',
            'cron' => null,
            'run_at' => '2026-11-12T10:00:00+00:00',
            'text' => 'list open incidents',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.mode', 'task')
            ->assertJsonPath('data.cron', null)
            ->assertJsonPath('data.run_at', '2026-11-12T17:00:00+07:00');

        $agent = AiAgent::where('dingtalk_conversation_id', 'cid-1')->first();
        $this->assertSame(AiAgentEngine::Go, $agent->engine);
        $this->assertSame(AiAgentFrequency::Once, $agent->frequency);
        $this->assertSame('2026-11-12 17:00', $agent->run_at->format('Y-m-d H:i')); // app tz (WIB)
    }

    public function test_short_no_sequences_per_conversation(): void
    {
        $this->storeReminder();
        $this->storeReminder();

        $otherChat = $this->storeReminder(['conversation_id' => 'cid-2', 'staff_id' => 'staff-2']);

        $otherChat->assertStatus(200)->assertJsonPath('data.no', 1);
        $this->assertSame(2, AiAgent::where('dingtalk_conversation_id', 'cid-1')->max('short_no'));
    }

    public function test_store_rejects_invalid_cron(): void
    {
        $this->storeReminder(['cron' => 'not a cron'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid cron expression.');
    }

    public function test_store_requires_either_cron_or_run_at(): void
    {
        $this->storeReminder(['cron' => null, 'run_at' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cron']);
    }

    public function test_store_enforces_per_chat_cap(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->storeReminder();
        }

        $this->storeReminder()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Reminder limit reached (max 20 per chat). Cancel one first.');
    }

    public function test_index_lists_active_reminders_only(): void
    {
        $this->storeReminder(); // #1 active cron reminder
        $this->storeReminder(['text' => 'future one-shot', 'cron' => null, 'run_at' => now()->addHour()->toIso8601String()]); // #2 active
        $this->storeReminder(['text' => 'past one-shot', 'cron' => null, 'run_at' => now()->subHour()->toIso8601String()]); // #3 already fired

        // Cancel #1 — only the future one-shot stays active in this chat.
        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/agent-schedules/1', ['conversation_id' => 'cid-1'])
            ->assertStatus(200);

        // Another chat's reminders are invisible here.
        $this->storeReminder(['conversation_id' => 'cid-2', 'staff_id' => 'staff-2']);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/agent-schedules?conversation_id=cid-1');

        $response->assertStatus(200);

        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('future one-shot', $items->first()['text']);
        $this->assertNull($items->first()['cron']);
        $this->assertNotNull($items->first()['run_at']);
        $this->assertSame(2, $items->first()['no']);
    }

    public function test_destroy_cancels_reminder_and_keeps_the_row(): void
    {
        $this->storeReminder();

        $response = $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/agent-schedules/1', ['conversation_id' => 'cid-1']);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Reminder #1 cancelled.');

        $agent = AiAgent::where('dingtalk_conversation_id', 'cid-1')->first();
        $this->assertNotNull($agent); // soft cancel — row kept
        $this->assertFalse($agent->enabled);
        $this->assertNull($agent->cron_expression);
        $this->assertNull($agent->run_at);
    }

    public function test_destroy_miss_returns_404(): void
    {
        $this->storeReminder();

        // Unknown number in this chat.
        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/agent-schedules/9', ['conversation_id' => 'cid-1'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'No reminder #9 in this chat.');

        // Valid number but a different chat — ownership mismatch.
        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/agent-schedules/1', ['conversation_id' => 'cid-other'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'No reminder #1 in this chat.');
    }

    public function test_token_without_the_agent_schedules_endpoint_is_rejected(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $restricted = $user->createToken('restricted', ['*']);
        PersonalAccessToken::find($restricted->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode(['incidents'])])
            ->save();

        $this->withHeader('Authorization', 'Bearer '.$restricted->plainTextToken)
            ->postJson('/api/v1/agent-schedules', [
                'conversation_id' => 'cid-1',
                'conversation_type' => '1',
                'staff_id' => 'staff-1',
                'mode' => 'message',
                'text' => 'x',
                'cron' => '5 9 * * *',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_token_with_only_the_agent_schedules_endpoint_is_accepted(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $allowed = $user->createToken('agent-only', ['*']);
        PersonalAccessToken::find($allowed->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode(['agent-schedules'])])
            ->save();

        $this->withHeader('Authorization', 'Bearer '.$allowed->plainTextToken)
            ->postJson('/api/v1/agent-schedules', [
                'conversation_id' => 'cid-1',
                'conversation_type' => '1',
                'staff_id' => 'staff-1',
                'mode' => 'message',
                'text' => 'x',
                'cron' => '5 9 * * *',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.no', 1);
    }
}
