<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            // Vector embedding of searchable_content (text-embedding-3-large, 3072 dims).
            // Stored as JSON; cosine similarity is computed app-side over the candidate
            // set (no native vector index needed for this corpus size).
            $table->json('embedding')->nullable()->after('context_content');
        });
    }

    public function down(): void
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });
    }
};
