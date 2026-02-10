<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fixes Spatie Laravel Permission guard_name mismatch.
     * All roles and permissions must use the same guard_name as config('auth.defaults.guard') = 'web'
     * otherwise permission checks will fail, causing "invalid credentials" errors during login.
     */
    public function up(): void
    {
        // Fix guard_name for all Spatie permission tables to match config('auth.defaults.guard') = 'web'
        DB::statement("UPDATE roles SET guard_name = 'web' WHERE guard_name != 'web'");
        DB::statement("UPDATE permissions SET guard_name = 'web' WHERE guard_name != 'web'");
        DB::statement("UPDATE model_has_roles SET guard_name = 'web' WHERE guard_name != 'web'");
        DB::statement("UPDATE model_has_permissions SET guard_name = 'web' WHERE guard_name != 'web'");
        DB::statement("UPDATE role_has_permissions SET guard_name = 'web' WHERE guard_name != 'web'");
    }

    /**
     * Reverse the migrations.
     *
     * Note: Rollback is not recommended as guard_name should always match auth.defaults.guard.
     * This method is provided for completeness but should not be used in production.
     */
    public function down(): void
    {
        // Rollback not recommended - guard_name should always match config('auth.defaults.guard')
    }
};
