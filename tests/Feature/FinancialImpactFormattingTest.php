<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Filament\Actions\FinancialImpactFormSchema;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Owner 2026-11-20: the Financial Impact fields read AND display Indonesian
 * money — "1.500.000,00" (dot thousands, comma decimals), empty renders
 * "0,00". A pasted value carrying dots/commas must be read back correctly:
 * "1.234.567" = 1234567.00, "1.234.567,89" = 1234567.89, "Rp 1.234.567"
 * strips the prefix. Database values keep their existing numeric shape
 * (decimal:2) — the parse lives in ONE place (the dehydrator) so anything
 * the user types or pastes is normalized before the numeric rules run.
 */
class FinancialImpactFormattingTest extends TestCase
{
    use RefreshDatabase;

    /** id-ID reading rules: comma = last decimal separator, dots = thousands;
     * a lone dot followed by 1–2 digits reads as an English decimal ("1.50"),
     * otherwise dots are thousands ("1.500" = 1500). No digits → null. */
    public function test_the_parser_reads_pasted_values_as_indonesian_money(): void
    {
        $cases = [
            '1.234.567' => 1234567.0,
            '1.234.567,00' => 1234567.0,
            '1.234.567,89' => 1234567.89,
            'Rp 1.234.567' => 1234567.0,
            '1500000' => 1500000.0,
            '1.500' => 1500.0,
            '1.50' => 1.5,
            '1,5' => 1.5,
            '1.234.500,75' => 1234500.75,
            '0' => 0.0,
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame(
                $expected,
                FinancialImpactFormSchema::parse($input),
                "\"{$input}\" must read back as {$expected}",
            );
        }

        $this->assertNull(FinancialImpactFormSchema::parse(''), 'empty reads as null (the defaults apply)');
        $this->assertNull(FinancialImpactFormSchema::parse('Rp'), 'no digits at all reads as null');
    }

    public function test_the_formatter_renders_indonesian_money_with_two_decimals(): void
    {
        $this->assertSame('1.234.567,00', FinancialImpactFormSchema::format(1234567.0));
        $this->assertSame('1.234.567,89', FinancialImpactFormSchema::format(1234567.89));
        $this->assertSame('0,00', FinancialImpactFormSchema::format(0.0));
        $this->assertSame('0,00', FinancialImpactFormSchema::format(null), 'empty state renders the 0,00 default');
    }

    public function test_a_pasted_value_persists_as_the_right_number(): void
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo(['view incidents', 'manage incidents']);

        Livewire::actingAs($user)
            ->test(\App\Filament\Resources\IncidentResource\Pages\CreateIncident::class)
            ->fillForm([
                'title' => 'Money format probe',
                'severity' => 'P2',
                'classification' => IncidentClassification::Incident->value,
                'incident_date' => now()->format('Y-m-d H:i:s'),
                'incident_type' => 'Tech',
                'incident_source' => 'Internal',
                'entry_date_tech_risk' => now()->format('Y-m-d H:i:s'),
                'potential_fund_loss' => '1.234.567',
                'recovered_fund' => '500.000,50',
                'fund_loss' => 'Rp 2.000.000',
            ])
            ->call('create');

        $incident = Incident::query()->latest('id')->first();
        $this->assertNotNull($incident);
        $this->assertSame(1234567.0, (float) $incident->potential_fund_loss);
        $this->assertSame(500000.5, (float) $incident->recovered_fund);
        $this->assertSame(2000000.0, (float) $incident->fund_loss);
    }

    public function test_existing_database_values_render_in_the_indonesian_format(): void
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo(['view incidents', 'manage incidents']);

        $incident = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'fund_loss' => 1500000,
            'recovered_fund' => 0,
            'potential_fund_loss' => 1234567.89,
        ]);

        Livewire::actingAs($user)
            ->test(\App\Filament\Resources\IncidentResource\Pages\EditIncident::class, [
                'record' => $incident->getRouteKey(),
            ])
            ->assertSuccessful()
            ->assertSeeHtml('1.500.000,00', false)
            ->assertSeeHtml('0,00', false)
            ->assertSeeHtml('1.234.567,89', false);
    }
}
