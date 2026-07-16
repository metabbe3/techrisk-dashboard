<?php

namespace Tests\Unit\Services\WarRoom;

use App\Models\ActionImprovement;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarRoomDraftActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_drafts_action_improvements_from_report_recommendations(): void
    {
        $session = WarRoomSession::factory()->completed()->create([
            'final_report' => [
                'improvement_recommendations' => "- Add connection pool monitoring\n- Increase DB pool size\n- Add a circuit breaker on the gateway",
            ],
        ]);

        $count = app(WarRoomService::class)->draftActionImprovements($session);

        $this->assertSame(3, $count);
        $drafts = ActionImprovement::where('incident_id', $session->incident_id)->where('status', 'draft')->get();
        $this->assertCount(3, $drafts);
        $this->assertStringContainsString('connection pool monitoring', $drafts->pluck('title')->implode(' '));
        // Drafts must not be 'pending' — keeps them out of reminder/overdue logic until reviewed.
        $this->assertSame(0, ActionImprovement::where('incident_id', $session->incident_id)->where('status', 'pending')->count());
    }

    public function test_drafts_zero_when_no_recommendations(): void
    {
        $session = WarRoomSession::factory()->completed()->create([
            'final_report' => ['improvement_recommendations' => ''],
        ]);

        $this->assertSame(0, app(WarRoomService::class)->draftActionImprovements($session));
        $this->assertSame(0, ActionImprovement::where('incident_id', $session->incident_id)->count());
    }

    public function test_falls_back_to_raw_report_html_when_parsed_section_empty(): void
    {
        $session = WarRoomSession::factory()->completed()->create([
            'final_report' => ['improvement_recommendations' => ''],
            'final_report_html' => "## Improvement Recommendations\n- Harden the firewall rules\n- Rotate credentials",
        ]);

        $count = app(WarRoomService::class)->draftActionImprovements($session);

        $this->assertSame(2, $count);
    }
}
