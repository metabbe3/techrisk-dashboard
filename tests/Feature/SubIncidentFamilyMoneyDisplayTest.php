<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PROJ-022 (owner 2026-11-16): a super-incident's money display — the list
 * money columns and the view page's Financial Impact — is the FAMILY total
 * (itself + its subs), matching the withSubIncidents roll-up the dashboard
 * money tiles already use. Subs and standalone incidents keep their own
 * values. Display-time only: storage, exports and metric queries untouched.
 */
class SubIncidentFamilyMoneyDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function seedFamily(): array
    {
        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now()->startOfDay()->addHours(10),
            'potential_fund_loss' => 5000000,
            'recovered_fund' => 1000000,
            'fund_loss' => 1000000,
        ]);

        $sub = Incident::factory()->subOf($super)->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now()->startOfDay()->addHours(10),
            'potential_fund_loss' => 2000000,
            'recovered_fund' => 2000000,
            'fund_loss' => 1000000,
        ]);

        return [$super, $sub];
    }

    private function manager(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->assignRole('admin'); // skips applyUserYearAccess record scoping
        $user->givePermissionTo('view incidents');

        return $user;
    }

    private static function idr(int $amount): string
    {
        return Number::currency((float) $amount, 'IDR', config('app.locale'));
    }

    public function test_super_family_money_sums_itself_and_its_subs(): void
    {
        [$super, $sub] = $this->seedFamily();

        $this->assertSame(7000000.0, $super->familyMoney()['potential']);
        $this->assertSame(3000000.0, $super->familyMoney()['recovered']);
        $this->assertSame(2000000.0, $super->familyMoney()['actual']);
        $this->assertEqualsWithDelta(3000000.0 / 7000000.0 * 100, $super->familyRecoveryRate(), 0.001);

        // A sub and a standalone show their own values — the roll-up lands
        // on the super only (one level deep; a sub has no children).
        $this->assertSame(2000000.0, $sub->familyMoney()['potential']);
        $standalone = Incident::factory()->create(['fund_loss' => 9]);
        $this->assertSame(9.0, $standalone->familyMoney()['actual']);
    }

    public function test_zero_potential_keeps_the_family_recovery_rate_at_zero(): void
    {
        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'fund_loss' => 0,
        ]);
        Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'fund_loss' => 0,
        ]);

        $this->assertSame(0.0, $super->familyRecoveryRate());
    }

    public function test_list_shows_family_money_on_the_super_row_and_own_money_on_subs(): void
    {
        [$super, $sub] = $this->seedFamily();

        // Collapsed: the super row displays the family potential (5M own +
        // 2M sub) — the 7M appears nowhere else on a collapsed list.
        Livewire::actingAs($this->manager())
            ->test(ListIncidents::class)
            ->assertSee(self::idr(7000000))
            // Expanded: the sub keeps its own value while the super row
            // keeps the family total.
            ->call('toggleExpandedSubIncidents', $super->id)
            ->assertSee(self::idr(2000000))
            ->assertSee(self::idr(7000000));
    }

    public function test_view_page_shows_family_money_for_a_super(): void
    {
        [$super, $sub] = $this->seedFamily();

        // The Financial Impact entries show the family totals (the super's
        // own 5M is replaced) — no footer summaries on a view page, so 5M
        // appears nowhere once the entries roll up.
        Livewire::actingAs($this->manager())
            ->test(ViewIncident::class, ['record' => (string) $super->id])
            ->assertSee(self::idr(7000000))
            ->assertDontSee(self::idr(5000000));
    }
}
