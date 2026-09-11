<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('war_room_messages', function (Blueprint $table) {
            // Execution start timestamp — the stuck-running reaper measures
            // runtime from here instead of created_at (which counts queue wait).
            $table->timestamp('running_since')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('war_room_messages', function (Blueprint $table) {
            $table->dropColumn('running_since');
        });
    }
};
