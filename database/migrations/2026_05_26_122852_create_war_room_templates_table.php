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
        Schema::create('war_room_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('selected_agents');
            $table->unsignedInteger('max_rounds')->default(2);
            $table->string('model', 100)->nullable();
            $table->string('moderator_model', 100)->nullable();
            $table->boolean('enable_web_search')->default(false);
            $table->boolean('deep_analysis')->default(true);
            $table->text('user_instructions')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('war_room_templates');
    }
};
