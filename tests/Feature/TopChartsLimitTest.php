<?php

namespace Tests\Feature;

use App\Filament\Widgets\IncidentsByLabelChart;
use App\Filament\Widgets\IncidentsByPicChart;
use App\Models\Incident;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class TopChartsLimitTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> rank 1..7 — counts 7 down to 1 */
    private array $names = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf'];

    private function eligibleIncident(): Incident
    {
        return Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'incident_status' => 'Open',
            'fund_status' => 'Non fundLoss',
            'incident_date' => now()->startOfYear()->addDays(10)->toDateTimeString(),
        ]);
    }

    public function test_label_chart_shows_top_five_labels_only(): void
    {
        Cache::flush();

        foreach ($this->names as $rank => $name) {
            $label = Label::create(['name' => $name]);
            for ($i = 0; $i < 7 - $rank; $i++) {
                $this->eligibleIncident()->labels()->attach($label);
            }
        }

        Livewire::test(IncidentsByLabelChart::class)
            ->assertSee('Incidents by Label (Top 5)')
            ->assertSee('alpha')
            ->assertSee('bravo')
            ->assertSee('charlie')
            ->assertSee('delta')
            ->assertSee('echo')
            ->assertDontSee('foxtrot')
            ->assertDontSee('golf');
    }

    public function test_pic_chart_shows_top_five_pics_only(): void
    {
        Cache::flush();
        Notification::fake(); // pivot hooks notify PIC + admins

        foreach ($this->names as $rank => $name) {
            $user = User::factory()->create(['name' => $name]);
            for ($i = 0; $i < 7 - $rank; $i++) {
                $this->eligibleIncident()->pics()->attach($user);
            }
        }

        Livewire::test(IncidentsByPicChart::class)
            ->assertSee('Incidents by Person In Charge (Top 5)')
            ->assertSee('alpha')
            ->assertSee('bravo')
            ->assertSee('charlie')
            ->assertSee('delta')
            ->assertSee('echo')
            ->assertDontSee('foxtrot')
            ->assertDontSee('golf');
    }
}
