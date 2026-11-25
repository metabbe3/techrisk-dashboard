<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIC Incident (owner 2026-11-24): a second person-role pivot beside the
 * existing incident_pic (which is now labelled "PIC Tech Risk"). Display-only
 * — no notifications, no access-grant scope, no metrics.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_pic_incident')) {
            Schema::create('incident_pic_incident', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unique(['incident_id', 'user_id']);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_pic_incident');
    }
};
