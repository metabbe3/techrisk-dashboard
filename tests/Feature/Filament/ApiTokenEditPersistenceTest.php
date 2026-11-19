<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApiTokenResource\Pages\EditApiToken;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * BUG: the edit page said "Token updated successfully" but Allowed
 * Endpoints / Auto-Renewal reverted after refresh — Sanctum's parent
 * model $fillable whitelist silently dropped them from the mass-assignment
 * update() (create works because it uses forceFill()). This test pins the
 * DB-level persistence of every editable field.
 */
class ApiTokenEditPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // Panel access + the resource gate both key on these.
        Permission::firstOrCreate(['name' => 'access dashboard']);
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $user = User::factory()->create();
        $user->givePermissionTo('access dashboard', 'manage api tokens');

        return $user;
    }

    private function makeToken(User $owner): PersonalAccessToken
    {
        // Mirrors CreateApiToken: createToken + forceFill for the extra columns.
        $token = $owner->createToken('Original Token', ['*'])->accessToken;
        $token->forceFill([
            'expires_at' => now()->addMonths(6),
            'renewal_minutes' => 43200,
            'allowed_endpoints' => ['incidents'],
        ])->save();

        return $token;
    }

    public function test_edit_persists_endpoints_renewal_and_abilities(): void
    {
        $token = $this->makeToken(User::factory()->create());

        Livewire::actingAs($this->admin())
            ->test(EditApiToken::class, ['record' => $token->getKey()])
            ->assertFormSet([
                'allowed_endpoints' => ['incidents'],
                'renewal_minutes' => '43200',
            ])
            ->fillForm([
                'abilities' => ['*', 'read:pii'],
                'allowed_endpoints' => ['incidents', 'stats'],
                'renewal_minutes' => '86400',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $token->refresh();

        $this->assertSame(['incidents', 'stats'], $token->allowed_endpoints);
        $this->assertSame(86400, $token->renewal_minutes);
        $this->assertSame(['*', 'read:pii'], $token->abilities);
    }

    public function test_edit_keeps_owner_and_expires_at_unchanged(): void
    {
        $owner = User::factory()->create();
        $token = $this->makeToken($owner);
        $expiresAt = $token->expires_at->copy();

        Livewire::actingAs($this->admin())
            ->test(EditApiToken::class, ['record' => $token->getKey()])
            ->fillForm(['name' => 'Renamed Token'])
            ->call('save')
            ->assertHasNoFormErrors();

        $token->refresh();

        $this->assertSame('Renamed Token', $token->name);
        $this->assertSame($owner->getKey(), $token->tokenable_id);
        $this->assertTrue($expiresAt->equalTo($token->expires_at));
    }
}
