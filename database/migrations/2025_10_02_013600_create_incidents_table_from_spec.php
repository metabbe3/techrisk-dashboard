<?php

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
        Schema::create('incidents', function (Blueprint $table) {
            $table->id(); // Standard auto-incrementing ID

            // Identity
            $table->string('no')->unique(); // e.g., '2025_IN_P1...'
            $table->string('title');

            // Timestamps
            $table->dateTime('incident_date');
            $table->dateTime('entry_date_tech_risk');
            $table->dateTime('discovered_at')->nullable();
            $table->dateTime('stop_bleeding_at')->nullable();

            // Classification
            $table->enum('classification', ['Incident', 'Issue']);
            $table->enum('severity', ['P1', 'P2', 'P3', 'P4', 'Non Incident']);
            $table->boolean('glitch_flag')->default(false);
            $table->enum('incident_type', ['Tech', 'Non-tech']);
            $table->enum('incident_source', ['Internal', 'External']);
            $table->string('incident_category')->nullable();

            // Status & Analysis
            $table->enum('incident_status', ['Open', 'In progress', 'Finalization', 'Completed'])->default('Open');
            $table->text('summary')->nullable();
            $table->text('remark')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('improvements')->nullable();

            // Financials
            $table->enum('fund_status', ['Non fundLoss', 'Confirmed loss', 'Potential recovery'])->nullable();
            $table->decimal('potential_fund_loss', 15, 2)->default(0);
            $table->decimal('recovered_fund', 15, 2)->default(0);
            $table->decimal('fund_loss', 15, 2)->default(0);
            $table->string('loss_taken_by')->nullable();

            // Parties
            $table->string('pic')->nullable(); // Person In Charge
            $table->string('reported_by')->nullable();
            $table->string('third_party_client')->nullable();

            // Documents & Evidence
            $table->text('evidence')->nullable();
            $table->string('evidence_link')->nullable();
            $table->boolean('risk_incident_form_cfm')->default(false);
            $table->text('action_improvement_tracking')->nullable();
            $table->boolean('goc_upload')->default(false);
            $table->boolean('teams_upload')->default(false);
            $table->boolean('doc_signed')->default(false);
            $table->string('investigation_pic_status')->nullable();

            $table->timestamps(); // Adds created_at and updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
