<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Red-team fix W8 (2026-11-21): empty/null allowed_endpoints used to mean
 * "allow all endpoints" — the default created unrestricted tokens without
 * anyone choosing that. The middleware now DENIES empty selections and
 * grants all endpoints only on the explicit `*` sentinel, so every existing
 * unrestricted token is stamped `["*"]` here (otherwise all live tokens —
 * including the DingTalk agent's — would start failing after deploy).
 * prod run: owner-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('personal_access_tokens')
            ->where(function ($q) {
                $q->whereNull('allowed_endpoints')
                    ->orWhere('allowed_endpoints', '')
                    ->orWhere('allowed_endpoints', '[]')
                    ->orWhere('allowed_endpoints', 'null');
            })
            ->update(['allowed_endpoints' => '["*"]']);
    }

    public function down(): void
    {
        // Nothing to reverse — the previous state was an implicit default,
        // not data. Restoring it would re-open the unrestricted-by-default
        // hole; leave tokens explicit.
    }
};
