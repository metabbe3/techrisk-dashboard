<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('investigation_documents', function (Blueprint $table) {
            $table->string('markdown_path')->nullable()->after('file_path');
            $table->timestamp('markdown_converted_at')->nullable()->after('markdown_path');
            $table->string('markdown_conversion_status')->default('pending')->after('markdown_converted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investigation_documents', function (Blueprint $table) {
            $table->dropColumn(['markdown_path', 'markdown_converted_at', 'markdown_conversion_status']);
        });
    }
};
