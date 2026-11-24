<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only mirror of DOHA fund-loss tickets (DOHA API v1, contract:
 * doha_temp/DOHA API Integration Guide.pdf). Keyed on the DOHA UUID —
 * `ref` (INC-0050) is editable in DOHA and never a key.
 *
 * ⚠ doha_created_at / doha_updated_at are RAW strings on purpose: DOHA
 * returns naive UTC strings and the sync watermark must round-trip them
 * byte-identically. A datetime cast would parse them in app timezone
 * (Asia/Jakarta) and silently shift them 7 hours. Display converts via
 * DohaTicket::doha_*_wib accessors.
 *
 * Deliberately NOT wired into any incident metric surface (BUG-005/006/007
 * constraint) — incident_id is the ONLY join to Incidents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doha_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('doha_id', 36)->unique();
            $table->string('ref', 64)->index();
            $table->text('title')->nullable();
            $table->string('bank')->nullable();
            $table->string('domain', 64)->nullable();
            $table->string('team')->nullable();
            $table->string('category')->nullable();
            $table->text('service_impact')->nullable();
            $table->text('root_cause')->nullable();
            $table->string('business_type')->nullable();
            $table->decimal('total_transaction', 20, 2)->nullable();
            $table->decimal('potential_loss', 20, 2)->nullable();
            $table->decimal('recovered', 20, 2)->nullable();
            $table->string('priority', 64)->nullable();
            $table->string('status', 64)->nullable();
            $table->date('transaction_date')->nullable();
            $table->string('transaction_time', 8)->nullable();
            $table->date('close_date')->nullable();
            $table->text('recovery_action')->nullable();
            $table->text('follow_up_action')->nullable();
            $table->string('created_by')->nullable();
            $table->string('created_by_edit')->nullable();
            $table->string('doha_created_at', 32)->nullable();
            $table->string('doha_updated_at', 32)->nullable();
            $table->foreignId('incident_id')->nullable()
                ->constrained('incidents')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doha_tickets');
    }
};
