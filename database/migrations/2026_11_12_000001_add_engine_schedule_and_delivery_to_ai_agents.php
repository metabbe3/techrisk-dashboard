<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Agent unification (PROJ: Go engine + dashboard control plane): the engine
// switch (php single-call | go tool-loop | static deliver-text), one-shot
// scheduling (run_at), and the DingTalk delivery target chat reminders bind
// to. Default stays 'php' at the column level; existing rows are flipped to
// 'go' below (owner decision 2026-11-11) so test fixtures keep meaning "php".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table) {
            $table->string('engine')->default('php')->after('model');
            $table->timestamp('run_at')->nullable()->after('cron_expression');
            $table->unsignedInteger('short_no')->nullable()->after('run_at');
            $table->string('dingtalk_conversation_type', 1)->nullable()->after('short_no');
            $table->string('dingtalk_conversation_id')->nullable()->after('dingtalk_conversation_type');
            $table->string('dingtalk_staff_id')->nullable()->after('dingtalk_conversation_id');
            // Per-conversation reminder numbers ("cancel #3") — NULL conversation
            // rows (normal agents) never collide: MySQL unique allows many NULLs.
            $table->unique(['dingtalk_conversation_id', 'short_no'], 'ai_agents_conversation_short_no_unique');
        });

        // Owner decision 2026-11-11: flip every existing agent to the Go engine.
        DB::table('ai_agents')->update(['engine' => 'go']);

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->string('delivery_status')->nullable()->after('error_message'); // sent | failed
            $table->text('delivery_error')->nullable()->after('delivery_status');
        });

        // Synthetic agent owning DingTalk chat-turn run rows — never dispatched
        // (enabled=false, Manual); the turn-report API inserts runs directly.
        DB::table('ai_agents')->insert([
            'id' => \App\Models\AiAgent::CHAT_TURNS_AGENT_ID,
            'name' => 'DingTalk Bot (chat turns)',
            'description' => 'Synthetic agent — every DingTalk chat turn is recorded as a run here. Not runnable.',
            'instructions' => 'Not runnable.',
            'engine' => 'php',
            'frequency' => 'Manual',
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('ai_agents', function (Blueprint $table) {
            $table->dropUnique('ai_agents_conversation_short_no_unique');
            $table->dropColumn(['engine', 'run_at', 'short_no', 'dingtalk_conversation_type', 'dingtalk_conversation_id', 'dingtalk_staff_id']);
        });

        DB::table('ai_agents')->where('id', \App\Models\AiAgent::CHAT_TURNS_AGENT_ID)->delete();

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'delivery_error']);
        });
    }
};
