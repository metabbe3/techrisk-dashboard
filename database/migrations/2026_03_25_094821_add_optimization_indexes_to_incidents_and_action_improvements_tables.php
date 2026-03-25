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
        // Add composite index for MTBF queries on incidents table
        Schema::table('incidents', function (Blueprint $table) {
            $table->index(['classification', 'severity', 'incident_date'], 'idx_classification_severity_incident_date');
            $table->index(['fund_status', 'incident_date'], 'idx_fund_status_incident_date');
        });

        // Add index for action improvements status filtering
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'idx_status_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex('idx_classification_severity_incident_date');
            $table->dropIndex('idx_fund_status_incident_date');
        });

        Schema::table('action_improvements', function (Blueprint $table) {
            $table->dropIndex('idx_status_created_at');
        });
    }
};
