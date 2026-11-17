<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJ-018: Start Investigation — one Notion investigation page per
 * incident/issue (32-hex page ID, last synced body, sync timestamp) plus the
 * round-9 deleted flag — set when the linked page turned up deleted in
 * Notion, cleared on a successful refresh or re-investigation — and the
 * round-10 unlinked flag: a soft unlink keeps the page ID (Relink picks it
 * back up; Start Investigation continues the page), set at unlink time and
 * cleared on a successful refresh/re-link or a hard unlink.
 * NOTE (CLAUDE.md owner-only guardrail): running this on PRODUCTION needs
 * owner approval — it is a prod DB migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('notion_investigation_page_id', 64)->nullable();
            $table->longText('notion_investigation_markdown')->nullable();
            $table->timestamp('notion_investigation_synced_at')->nullable();
            $table->timestamp('notion_investigation_deleted_at')->nullable();
            $table->timestamp('notion_investigation_unlinked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn([
                'notion_investigation_page_id',
                'notion_investigation_markdown',
                'notion_investigation_synced_at',
                'notion_investigation_deleted_at',
                'notion_investigation_unlinked_at',
            ]);
        });
    }
};
