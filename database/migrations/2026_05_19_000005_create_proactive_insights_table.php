<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proactive_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('insight_type', 50);
            $table->text('content');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index('incident_id');
            $table->index(['user_id', 'is_read']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proactive_insights');
    }
};
