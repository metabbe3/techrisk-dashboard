<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_user', function (Blueprint $table) {
            // Team-viewer role (owner 2026-11-20): a user's responsible-team
            // mapping. Categories of type 'responsible_team' are the team
            // registry; ids (not names) so team renames stay in sync.
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['category_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Set when an admin edits a user's teams by hand — users:sync-teams
            // skips flagged users so the mapping survives the next run.
            $table->boolean('teams_manual')->default(false)->after('can_login');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('teams_manual');
        });

        Schema::dropIfExists('category_user');
    }
};
