<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('war_room_agent_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role_key', 50)->unique();
            $table->string('display_name');
            $table->string('icon', 50)->nullable();
            $table->string('color', 30)->nullable();
            $table->text('system_prompt');
            $table->string('model_override', 100)->nullable();
            $table->boolean('enable_web_search')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('war_room_agent_configs');
    }
};
