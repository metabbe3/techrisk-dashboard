<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_email')->nullable();

            $table->string('field_type', 50)->index();
            $table->string('model', 100)->index();

            $table->unsignedInteger('input_length')->nullable();
            $table->unsignedInteger('output_length')->nullable();

            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable()->index();

            $table->unsignedInteger('response_time_ms')->nullable()->index();

            $table->boolean('success')->index();
            $table->text('error_message')->nullable();
            $table->string('api_request_id')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('requested_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'requested_at']);
            $table->index(['model', 'requested_at']);
            $table->index(['success', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
