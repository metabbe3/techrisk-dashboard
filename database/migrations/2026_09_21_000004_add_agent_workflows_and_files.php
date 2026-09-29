<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->boolean('include_documents')->default(false)->after('include_memory');
        });

        Schema::create('ai_workflows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_workflow_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained('ai_workflows')->cascadeOnDelete();
            // Agent deleted -> step disappears; the chain re-syncs on next workflow save.
            $table->foreignUuid('agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->integer('position');
            $table->timestamps();

            // One workflow per agent: an agent can't be in two chains at once.
            $table->unique('agent_id');
        });

        Schema::create('ai_agent_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->text('extracted_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_files');
        Schema::dropIfExists('ai_workflow_steps');
        Schema::dropIfExists('ai_workflows');

        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->dropColumn('include_documents');
        });
    }
};
