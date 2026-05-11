<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('war_room_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('current_round')->default(0);
            $table->unsignedInteger('max_rounds')->default(2);
            $table->string('model', 100)->nullable();
            $table->string('moderator_model', 100)->nullable();
            $table->boolean('enable_web_search')->default(false);
            $table->json('selected_agents');
            $table->json('incident_context')->nullable();
            $table->json('final_report')->nullable();
            $table->text('final_report_html')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('incident_id');
            $table->index('status');
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('war_room_sessions');
    }
};
