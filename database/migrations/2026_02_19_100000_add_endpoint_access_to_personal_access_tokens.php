<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Add column to store which endpoints this token can access
            // Stores as JSON array of endpoint identifiers
            $table->json('allowed_endpoints')->nullable()->after('abilities');

            // Add index for faster queries on tokens
            $table->index('last_used_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('personal_access_tokens_last_used_at_index');
            $table->dropColumn('allowed_endpoints');
        });
    }
};
