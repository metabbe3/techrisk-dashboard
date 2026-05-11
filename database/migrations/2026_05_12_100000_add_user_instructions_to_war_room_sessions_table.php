<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('war_room_sessions', function (Blueprint $table) {
            $table->text('user_instructions')->nullable()->after('incident_context');
        });
    }

    public function down(): void
    {
        Schema::table('war_room_sessions', function (Blueprint $table) {
            $table->dropColumn('user_instructions');
        });
    }
};
