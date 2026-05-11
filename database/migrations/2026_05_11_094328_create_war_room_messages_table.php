<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('war_room_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('war_room_sessions')->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->string('agent_role', 50);
            $table->string('role', 20);
            $table->text('content')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('web_search_context')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('session_id');
            $table->index(['session_id', 'round']);
            $table->index(['session_id', 'agent_role', 'round']);
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('war_room_messages');
    }
};
