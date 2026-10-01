<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJ-009 Part D: AI-generated retrospective stored on the incident.
 * NOTE (CLAUDE.md owner-only guardrail): running this on PRODUCTION needs
 * owner approval — it is a prod DB migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->longText('retro_markdown')->nullable();
            $table->timestamp('retro_generated_at')->nullable();
            $table->string('retro_model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['retro_markdown', 'retro_generated_at', 'retro_model']);
        });
    }
};
