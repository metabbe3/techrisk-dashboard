<?php

namespace Tests;

use App\Models\PersonalAccessToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Red-team fix W8: the middleware now DENIES tokens with an empty
        // allowed_endpoints (allow-all must be the explicit `*` sentinel).
        // Production backfills existing rows via
        // 2026_11_21_000001_backfill_allowed_endpoints_wildcard; tests
        // emulate the same state so plain createToken() fixtures keep
        // their pre-fix meaning. Tests pinning RESTRICTED behavior
        // forceFill their own endpoint lists after creation (update path —
        // this creating hook does not fire).
        PersonalAccessToken::creating(function (PersonalAccessToken $token): void {
            if (blank($token->allowed_endpoints)) {
                $token->forceFill(['allowed_endpoints' => ['*']]);
            }
        });
    }
}
