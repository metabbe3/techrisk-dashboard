<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Filament\Widgets\DashboardStatsOverview;
use App\Filament\Widgets\FundLossTrendChart;
use App\Filament\Widgets\RootCauseFundParetoChart;
use App\Models\Incident;
use App\Services\Ai\ChatContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PROJ-022: money surfaces opt into sub-incidents — counts roll up into the
 * super, but sub MONEY still counts on the fund trend, root-cause Pareto,
 * dashboard fund card and the chat executive summary (spec decision 5).
 */
class SubIncidentMoneyKeepsSubsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Super: counts, carries no money. Sub: carries the money (100) and a
     * root-cause bucket. Both metric-eligible, both this month.
     */
    private function seedSuperAndMoneySub(): array
    {
        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now(),
        ]);

        // Root cause + stop-bleeding satisfy the closure gate for Completed.
        $sub = Incident::factory()->subOf($super)->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now(),
            'incident_status' => IncidentStatus::Completed->value,
            'root_cause' => 'Test RCA',
            'stop_bleeding_at' => now(),
            'fund_status' => FundStatus::ConfirmedLoss->value,
            'fund_loss' => 100,
            'root_cause_category' => ['Fraud'],
        ]);

        return [$super, $sub];
    }

    public function test_fund_trend_keeps_sub_money(): void
    {
        $this->seedSuperAndMoneySub();

        $data = (new \ReflectionMethod(FundLossTrendChart::class, 'getData'))
            ->invoke(new FundLossTrendChart);

        $monthIndex = (int) now()->format('n') - 1;
        $this->assertSame(100.0, (float) $data['datasets'][0]['data'][$monthIndex]);
    }

    public function test_root_cause_pareto_keeps_sub_money(): void
    {
        $this->seedSuperAndMoneySub();

        $rows = (new \ReflectionMethod(RootCauseFundParetoChart::class, 'pareto'))
            ->invoke(new RootCauseFundParetoChart);

        $this->assertSame('Fraud', $rows[0]['category']);
        $this->assertSame(100.0, $rows[0]['loss']);
    }

    public function test_dashboard_counts_roll_up_while_the_fund_card_keeps_sub_money(): void
    {
        $this->seedSuperAndMoneySub();

        // Count card: the sub rolls up into the super — 1, not 2.
        $widget = app(DashboardStatsOverview::class);
        $stats = \Closure::bind(fn () => $this->calculateStats(), $widget, DashboardStatsOverview::class)();
        $values = collect($stats)->map(fn ($stat) => $stat->getValue())->values();
        $this->assertSame('1', (string) $values[0]);

        // Fund card (index 2, hand-rolled scope, no sub-exclusion): sub money stays.
        $this->assertSame('IDR 100', (string) $values[2]);
    }

    public function test_chat_executive_summary_keeps_sub_money_but_counts_roll_up(): void
    {
        $this->seedSuperAndMoneySub();

        $context = (new \ReflectionMethod(ChatContextService::class, 'getExecutiveSummaryContext'))
            ->invoke(app(ChatContextService::class));

        // Count rolls up (1, not 2)…
        $this->assertStringContainsString('This month: 1 incidents', $context);
        // …money keeps the sub's 100 (formatMoney: "Rp 100").
        $this->assertStringContainsString('This month fund loss: Rp 100', $context);
    }
}
