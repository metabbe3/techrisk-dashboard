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
        Schema::table('war_room_agent_configs', function (Blueprint $table) {
            $table->string('description')->nullable()->after('display_name');
            $table->json('skills')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('war_room_agent_configs', function (Blueprint $table) {
            $table->dropColumn(['description', 'skills']);
        });
    }
};
