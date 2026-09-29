<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table) {
            // Upstream deleted -> downstream becomes independent (nullOnDelete).
            $table->foreignUuid('depends_on_agent_id')->nullable()->after('include_context')
                ->constrained('ai_agents')->nullOnDelete();
            $table->foreignUuid('reports_to_agent_id')->nullable()->after('depends_on_agent_id')
                ->constrained('ai_agents')->nullOnDelete();
            $table->boolean('include_memory')->default(false)->after('reports_to_agent_id');
        });

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            // Which run triggered this one (chain provenance).
            $table->foreignUuid('triggered_by_run_id')->nullable()->after('agent_id')
                ->constrained('ai_agent_runs')->nullOnDelete();
        });

        Schema::create('ai_agent_memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // run_id nullOnDelete on purpose: runs prune after 90 days, lessons persist.
            $table->foreignUuid('agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->foreignUuid('run_id')->nullable()->constrained('ai_agent_runs')->nullOnDelete();
            $table->string('kind');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_memories');

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->dropForeign(['triggered_by_run_id']);
            $table->dropColumn('triggered_by_run_id');
        });

        Schema::table('ai_agents', function (Blueprint $table) {
            $table->dropForeign(['depends_on_agent_id']);
            $table->dropForeign(['reports_to_agent_id']);
            $table->dropColumn(['depends_on_agent_id', 'reports_to_agent_id', 'include_memory']);
        });
    }
};
