<?php

declare(strict_types=1);

use App\Models\ApiAuditLog;
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
        Schema::create('api_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('request_id')->nullable()->index();
            $table->timestamp('request_timestamp')->index();
            $table->string('method', 10)->index();
            $table->text('endpoint');
            $table->json('query_params')->nullable();
            $table->json('request_body')->nullable();

            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_email')->nullable();
            $table->string('ip_address', 45)->index();
            $table->text('user_agent')->nullable();

            $table->timestamp('response_timestamp')->index();
            $table->integer('response_status')->index();
            $table->integer('response_time_ms')->index();
            $table->integer('response_size_bytes')->nullable();
            $table->json('response_data')->nullable();
            $table->text('error_message')->nullable();

            $table->string('environment', 20)->index();
            $table->string('app_version')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes for common queries
            $table->index(['request_timestamp', 'response_status']);
            $table->index(['user_id', 'request_timestamp']);
            $table->index(['environment', 'request_timestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_audit_logs');
    }
};
