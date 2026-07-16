<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_similar_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('similar_incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->decimal('similarity', 4, 3)->nullable();
            $table->string('match_type', 40)->nullable();
            $table->text('reasoning')->nullable();
            $table->json('dimensions')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One stored pairing per (source, similar). Explicit name: the default
            // incident_similar_incidents_incident_id_similar_incident_id_unique is
            // 65 chars, one over MySQL's 64-char identifier limit.
            $table->unique(['incident_id', 'similar_incident_id'], 'incident_similar_pair_unique');
            // Fast "active list for this incident" lookup.
            $table->index(['incident_id', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_similar_incidents');
    }
};
