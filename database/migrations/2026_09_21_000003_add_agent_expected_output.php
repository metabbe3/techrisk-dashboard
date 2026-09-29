<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->text('expected_output')->nullable()->after('instructions');
            $table->boolean('require_json')->default(false)->after('expected_output');
        });
    }

    public function down(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->dropColumn(['expected_output', 'require_json']);
        });
    }
};
