<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edit detection for the Notion sync (owner 2026-11-04): the watched
 * database's read-only "Last edited time" column, remembered per synced
 * page as the value its content was last fetched at. A later sync compares
 * column vs stored value and re-fetches changed pages. NULL = the page's
 * content has never flowed through the detection path (pre-feature rows,
 * or pages imported after the sweep's query window) — refreshed on the
 * next sweep, which doubles as the automatic backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notion_synced_pages', function (Blueprint $table) {
            // Raw Notion column value — compared verbatim, never parsed.
            $table->string('last_edited_at', 255)->nullable()->after('incident_id');
        });
    }

    public function down(): void
    {
        Schema::table('notion_synced_pages', function (Blueprint $table) {
            $table->dropColumn('last_edited_at');
        });
    }
};
