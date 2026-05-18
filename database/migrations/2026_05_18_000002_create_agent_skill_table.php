<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_skill', function (Blueprint $table) {
            $table->uuid('war_room_agent_config_id');
            $table->uuid('skill_id');
            $table->timestamps();

            $table->primary(['war_room_agent_config_id', 'skill_id']);

            $table->foreign('war_room_agent_config_id')
                ->references('id')
                ->on('war_room_agent_configs')
                ->cascadeOnDelete();

            $table->foreign('skill_id')
                ->references('id')
                ->on('skills')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_skill');
    }
};
