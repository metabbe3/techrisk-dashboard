<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_disabled_returns_true_when_disabled_at_is_set(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $tokenModel = $token->accessToken;
        $tokenModel->forceFill(['disabled_at' => now()])->save();

        $this->assertTrue($tokenModel->isDisabled());
    }

    public function test_is_disabled_returns_false_when_disabled_at_is_null(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $this->assertFalse($token->accessToken->isDisabled());
    }

    public function test_is_expired_returns_true_when_expires_at_is_past(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $tokenModel = $token->accessToken;
        $tokenModel->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertTrue($tokenModel->isExpired());
    }

    public function test_is_expired_returns_false_when_expires_at_is_future(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $tokenModel = $token->accessToken;
        $tokenModel->forceFill(['expires_at' => now()->addMonths(6)])->save();

        $this->assertFalse($tokenModel->isExpired());
    }

    public function test_is_expired_returns_false_when_expires_at_is_null(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $this->assertFalse($token->accessToken->isExpired());
    }

    public function test_is_inactive_returns_true_when_last_used_beyond_threshold(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $tokenModel = $token->accessToken;
        $tokenModel->forceFill(['last_used_at' => now()->subDays(91)])->save();

        $this->assertTrue($tokenModel->isInactive(90));
    }

    public function test_is_inactive_returns_false_when_last_used_within_threshold(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $tokenModel = $token->accessToken;
        $tokenModel->forceFill(['last_used_at' => now()->subDays(30)])->save();

        $this->assertFalse($tokenModel->isInactive(90));
    }

    public function test_is_inactive_returns_false_when_last_used_at_is_null(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $this->assertFalse($token->accessToken->isInactive(90));
    }

    public function test_renew_extends_expires_at_by_renewal_minutes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $tokenModel = $token->accessToken;
        $expiresAt = now()->addMonths(6);
        $tokenModel->forceFill([
            'expires_at' => $expiresAt,
            'renewal_minutes' => 43200, // 30 days
        ])->save();

        $originalExpiresAt = $tokenModel->expires_at->copy();
        $tokenModel->renew();

        $tokenModel->refresh();
        $this->assertEquals(43200, $originalExpiresAt->diffInMinutes($tokenModel->expires_at));
    }

    public function test_renew_does_nothing_when_renewal_minutes_is_null(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $tokenModel = $token->accessToken;
        $expiresAt = now()->addMonths(6);
        $tokenModel->forceFill([
            'expires_at' => $expiresAt,
            'renewal_minutes' => null,
        ])->save();

        $originalExpiresAt = $tokenModel->expires_at->timestamp;
        $tokenModel->renew();

        $tokenModel->refresh();
        $this->assertEquals($originalExpiresAt, $tokenModel->expires_at->timestamp);
    }

    public function test_scope_active_filters_out_disabled_tokens(): void
    {
        $user = User::factory()->create();
        $activeToken = $user->createToken('active');
        $disabledToken = $user->createToken('disabled');
        $disabledToken->accessToken->forceFill(['disabled_at' => now()])->save();

        $activeTokens = PersonalAccessToken::active()->get();

        $this->assertTrue($activeTokens->contains($activeToken->accessToken));
        $this->assertFalse($activeTokens->contains($disabledToken->accessToken));
    }

    public function test_token_hash_is_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $array = $token->accessToken->toArray();

        $this->assertArrayNotHasKey('token', $array);
    }
}
