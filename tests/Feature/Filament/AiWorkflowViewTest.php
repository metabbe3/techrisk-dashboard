<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AiWorkflowResource;
use App\Models\AiAgent;
use App\Models\AiWorkflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiWorkflowViewTest extends TestCase
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

    /**
     * Collections arrive uuid-keyed; the mermaid builder must reindex or every
     * node id collides (this exact bug shipped once).
     */
    public function test_view_page_builds_positional_mermaid_ids(): void
    {
        $a = AiAgent::create(['name' => 'Chain A', 'instructions' => 'x', 'frequency' => 'Manual']);
        $b = AiAgent::create(['name' => 'Chain B', 'instructions' => 'x', 'frequency' => 'Manual']);
        $workflow = AiWorkflow::create(['name' => 'Nightly Risk Pipeline']);
        app(\App\Services\Ai\AiWorkflowService::class)->saveSteps($workflow, [$a->id, $b->id]);

        $response = $this->actingAs($this->panelUser())
            ->withoutExceptionHandling()
            ->get(AiWorkflowResource::getUrl('view', ['record' => $workflow]))
            ->assertSuccessful();

        // Positional node ids + edges (raw: the attribute escapes > and quotes).
        $response->assertSee('s0 --&gt; s1', false);
        $response->assertSee('1. Chain A');
        $response->assertSee('Runs after Chain A completes');
    }

    /**
     * Under fpm the auditing observer attaches (audit.console only gates console
     * processes) and AuditableObserver::retrieved() type-hints the Auditable
     * contract — a model using the trait without implementing the contract 500s
     * the list page. Attach the observer explicitly: boot-order in the shared
     * phpunit process makes the config knob unreliable.
     */
    public function test_list_page_survives_the_auditing_observer(): void
    {
        AiWorkflow::create(['name' => 'Observer Canary']);
        AiWorkflow::observe(new \OwenIt\Auditing\AuditableObserver);

        $this->actingAs($this->panelUser())
            ->withoutExceptionHandling()
            ->get(AiWorkflowResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('Observer Canary');
    }
}
