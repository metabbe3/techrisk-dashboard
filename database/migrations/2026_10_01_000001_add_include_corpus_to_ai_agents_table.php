<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->boolean('include_corpus')->default(false)->after('include_documents');
        });
    }

    public function down(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->dropColumn('include_corpus');
        });
    }
};
