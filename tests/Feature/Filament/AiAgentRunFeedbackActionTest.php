<?php

namespace Tests\Feature\Filament;

use App\Enums\AiAgentRunStatus;
use App\Filament\Resources\AiAgentRunResource\Pages\ListAiAgentRuns;
use App\Models\AiAgent;
use App\Models\AiAgentMemory;
use App\Models\AiAgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiAgentRunFeedbackActionTest extends TestCase
{
    use RefreshDatabase;

    private function panelUser(): User
    {
        Permission::firstOrCreate(['name' => 'access dashboard']);
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $user = User::factory()->create();
        $user->givePermissionTo(['access dashboard', 'manage api tokens']);

        return $user;
    }

    private function completedRun(): AiAgentRun
    {
        $agent = AiAgent::create(['name' => 'Digest', 'instructions' => 'x', 'frequency' => 'Manual']);

        return AiAgentRun::create([
            'agent_id' => $agent->id,
            'status' => AiAgentRunStatus::Completed->value,
            'input' => 'x',
            'output' => 'digest text',
            'requested_at' => now(),
        ]);
    }

    public function test_give_feedback_action_stores_feedback_memory(): void
    {
        $run = $this->completedRun();

        Livewire::actingAs($this->panelUser())
            ->test(ListAiAgentRuns::class)
            ->callTableAction('give_feedback', $run, ['feedback' => 'Keep the digest under 5 bullets.'])
            ->assertHasNoTableActionErrors();

        $row = AiAgentMemory::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('Feedback', $row->kind->value);
        $this->assertSame($run->agent_id, $row->agent_id);
        $this->assertSame($run->id, $row->run_id);
        $this->assertSame('Keep the digest under 5 bullets.', $row->content);
    }

    public function test_feedback_requires_text(): void
    {
        $run = $this->completedRun();

        Livewire::actingAs($this->panelUser())
            ->test(ListAiAgentRuns::class)
            ->callTableAction('give_feedback', $run, ['feedback' => ''])
            ->assertHasTableActionErrors(['feedback']);

        $this->assertSame(0, AiAgentMemory::count());
    }
}
