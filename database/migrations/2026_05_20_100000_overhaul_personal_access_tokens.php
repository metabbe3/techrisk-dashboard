<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('expires_at');
            $table->integer('renewal_minutes')->nullable()->after('disabled_at');
            $table->index('disabled_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_service_account')->default(false)->after('access_expiry');
        });

        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->update(['expires_at' => now()->addMonths(6)]);
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['disabled_at']);
            $table->dropColumn(['disabled_at', 'renewal_minutes']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_service_account');
        });
    }
};
