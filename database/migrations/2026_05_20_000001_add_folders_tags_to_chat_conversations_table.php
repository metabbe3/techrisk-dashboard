<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->string('folder')->nullable()->after('model');
            $table->timestamp('pinned_at')->nullable()->after('memory_archived_at');
            $table->json('tags')->nullable()->after('folder');
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn(['folder', 'pinned_at', 'tags']);
        });
    }
};
