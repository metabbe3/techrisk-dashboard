<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('war_room_session_incidents', function (Blueprint $table) {
            $table->uuid('session_id');
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->primary(['session_id', 'incident_id']);
        });

        // Make incident_id nullable on sessions (multi-incident uses pivot)
        Schema::table('war_room_sessions', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('war_room_sessions', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable(false)->change();
        });

        Schema::dropIfExists('war_room_session_incidents');
    }
};
