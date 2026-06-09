<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('war_room_sessions', function (Blueprint $table) {
            $table->json('pre_analysis')->nullable()->after('context_summarized');
        });
    }

    public function down(): void
    {
        Schema::table('war_room_sessions', function (Blueprint $table) {
            $table->dropColumn('pre_analysis');
        });
    }
};
