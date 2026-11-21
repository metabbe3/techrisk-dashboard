<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJ-022: sub-incident hierarchy — a sub-incident links to its super
 * (main) incident via parent_incident_id, one level deep, self-referencing
 * FK. Deleting the super orphans its subs (nullOnDelete) — sub records are
 * never deleted on the super's behalf.
 * NOTE (CLAUDE.md owner-only guardrail): running this on PRODUCTION needs
 * owner approval — it is a prod DB migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('parent_incident_id')->nullable()->after('classification')
                ->constrained('incidents')->nullOnDelete();
            $table->index('parent_incident_id');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['parent_incident_id']);
            $table->dropIndex(['parent_incident_id']);
            $table->dropColumn('parent_incident_id');
        });
    }
};
