<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Proof workflow (owner 2026-11-20): worker-submitted proof waits on an
// approval — pending lives on the evidence row (submitted_by set, approved_at
// null), never as an action status, so statusOptions/filters/exports/AI
// surfaces don't ripple. file_path/original_filename become nullable because
// a submission may be a link only (chat / Notion URL — no encryption, no key).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_improvement_evidence', function (Blueprint $table) {
            // ponytail: explicit short FK names — MySQL caps identifiers at 64
            // chars (same trap the base migration dodges).
            $table->foreignId('submitted_by')->nullable()
                ->constrained('users', 'id', 'aie_submitted_by_foreign')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', 'id', 'aie_approved_by_foreign')->nullOnDelete();
            $table->string('link_url', 2048)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('file_path')->nullable()->change();
            $table->string('original_filename')->nullable()->change();
            $table->index(['submitted_by', 'approved_at'], 'aie_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::table('action_improvement_evidence', function (Blueprint $table) {
            $table->dropIndex('aie_pending_idx');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['link_url', 'approved_at']);
            // ->change() down is a no-op restore — harmless: nothing else
            // wrote null file_path before this migration existed.
        });
    }
};
