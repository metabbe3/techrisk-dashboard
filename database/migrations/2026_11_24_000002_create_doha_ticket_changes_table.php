<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change history for the DOHA ticket mirror: one row per changed field per
 * sync run that observed the change (field, old → new). created_at = the
 * sync run; deleted with its ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doha_ticket_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doha_ticket_id')->constrained('doha_tickets')->cascadeOnDelete();
            $table->string('field', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamps();
            $table->index(['doha_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doha_ticket_changes');
    }
};
