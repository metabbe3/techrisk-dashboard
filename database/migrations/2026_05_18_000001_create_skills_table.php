<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('domain')->nullable();
            $table->longText('content')->nullable();
            $table->json('frameworks')->nullable();
            $table->json('tags')->nullable();
            $table->string('difficulty')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source')->nullable();
            $table->string('source_id')->nullable();
            $table->string('version', 20)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('domain');
            $table->index('is_active');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
