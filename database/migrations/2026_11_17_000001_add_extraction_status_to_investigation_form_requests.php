<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigation_form_requests', function (Blueprint $table) {
            // The intake extraction runs on the queue (the Dana edge proxy
            // 504s long Livewire requests). Status lives on the request — the
            // shared draft is per-request, and a public no-login page must
            // resume polling after a reload.
            $table->string('extraction_status')->nullable();
            $table->dateTime('extraction_started_at', 3)->nullable();
            $table->json('extraction_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('investigation_form_requests', function (Blueprint $table) {
            $table->dropColumn(['extraction_status', 'extraction_started_at', 'extraction_result']);
        });
    }
};
