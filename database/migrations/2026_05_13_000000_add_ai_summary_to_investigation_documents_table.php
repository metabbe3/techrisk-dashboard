<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigation_documents', function (Blueprint $table) {
            $table->text('ai_summary')->nullable()->after('markdown_conversion_status');
            $table->string('ai_summary_model')->nullable()->after('ai_summary');
            $table->timestamp('ai_summary_at')->nullable()->after('ai_summary_model');
        });
    }

    public function down(): void
    {
        Schema::table('investigation_documents', function (Blueprint $table) {
            $table->dropColumn(['ai_summary', 'ai_summary_model', 'ai_summary_at']);
        });
    }
};
