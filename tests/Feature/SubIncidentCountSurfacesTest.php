<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Filament\Widgets\IncidentsByLabelChart;
use App\Filament\Widgets\IncidentsByPicChart;
use App\Filament\Widgets\IncidentsBySeverityChart;
use App\Filament\Widgets\IncidentsByTypeChart;
use App\Filament\Widgets\MonthlyIncidentsChart;
use App\Filament\Widgets\RiskHeatMatrixWidget;
use App\Models\Incident;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PROJ-022: the dashboard's dimension charts are count surfaces — subs roll
 * up into their supers and never appear as their own bucket weight.
 */
class SubIncidentCountSurfacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Super + sub, both P1/Incident/Tech, current month — the sub is the
     * only extra row; every chart must count 1, not 2.
     */
    private function seedSuperAndSub(): array
    {
        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_type' => 'Tech',
            'incident_date' => now(),
        ]);

        $sub = Incident::factory()->subOf($super)->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_type' => 'Tech',
            'incident_date' => now(),
        ]);

        return [$super, $sub];
    }

    private function chartData(string $class): array
    {
        return (new \ReflectionMethod($class, 'getData'))->invoke(new $class);
    }

    public function test_type_chart_counts_rolled_up(): void
    {
        $this->seedSuperAndSub();

        $data = $this->chartData(IncidentsByTypeChart::class);

        $this->assertSame(['Tech'], $data['labels']);
        $this->assertSame([1], $data['datasets'][0]['data']);
    }

    public function test_pic_chart_counts_rolled_up(): void
    {
        Notification::fake(); // pivot hooks notify PIC + admins over reverb
        [$super, $sub] = $this->seedSuperAndSub();
        $pic = User::factory()->create();
        $super->pics()->attach($pic);
        $sub->pics()->attach($pic);

        $data = $this->chartData(IncidentsByPicChart::class);

        $this->assertSame([$pic->name], $data['labels']);
        $this->assertSame([1], $data['datasets'][0]['data']);
    }

    public function test_severity_chart_counts_rolled_up(): void
    {
        $this->seedSuperAndSub();

        $data = (new \ReflectionMethod(IncidentsBySeverityChart::class, 'getSeverityData'))
            ->invoke(new IncidentsBySeverityChart);

        $p1 = collect($data)->firstWhere('severity', Severity::P1->value);
        $this->assertNotNull($p1);
        $this->assertSame(1, $p1['count']);
    }

    public function test_label_chart_counts_rolled_up(): void
    {
        [$super, $sub] = $this->seedSuperAndSub();
        $label = Label::firstOrCreate(['name' => 'Payments']);
        $super->labels()->attach($label);
        $sub->labels()->attach($label);

        $data = $this->chartData(IncidentsByLabelChart::class);

        $this->assertSame(['Payments'], $data['labels']);
        $this->assertSame([1], $data['datasets'][0]['data']);
    }

    public function test_monthly_chart_counts_rolled_up(): void
    {
        $this->seedSuperAndSub();

        $data = $this->chartData(MonthlyIncidentsChart::class);

        $monthIndex = (int) now()->format('n') - 1;
        $this->assertSame(1, $data['datasets'][0]['data'][$monthIndex]);
    }

    public function test_heat_matrix_counts_rolled_up(): void
    {
        $this->seedSuperAndSub();

        $total = Livewire::test(RiskHeatMatrixWidget::class)
            ->instance()->totalIncidents;

        $this->assertSame(1, $total);
    }
}
