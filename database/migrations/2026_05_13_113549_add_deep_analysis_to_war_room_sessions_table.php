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
        Schema::table('war_room_sessions', function (Blueprint $table) {
            $table->boolean('deep_analysis')->default(true)->after('enable_web_search');
        });
    }

    public function down(): void
    {
        Schema::table('war_room_sessions', function (Blueprint $table) {
            $table->dropColumn('deep_analysis');
        });
    }
};
