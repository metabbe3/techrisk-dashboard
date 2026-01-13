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
        // Add indexes to incidents table
        Schema::table('incidents', function (Blueprint $table) {
            $table->index('incident_status');
            $table->index('severity');
            $table->index('pic_id');
            $table->index('classification');
            $table->index('incident_source');

            // Composite indexes for common queries
            $table->index(['incident_status', 'incident_date']);
            $table->index(['severity', 'incident_date']);
            $table->index(['incident_type', 'incident_date']);
        });

        // Add indexes to action_improvements table
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->index('due_date');
            $table->index('status');
            $table->index('incident_id');
        });

        // Add index to status_updates table
        Schema::table('status_updates', function (Blueprint $table) {
            $table->index('status');
            $table->index(['incident_id', 'created_at']);
        });

        // Add index to investigation_documents table
        Schema::table('investigation_documents', function (Blueprint $table) {
            $table->index('incident_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['incident_status']);
            $table->dropIndex(['severity']);
            $table->dropIndex(['pic_id']);
            $table->dropIndex(['classification']);
            $table->dropIndex(['incident_source']);
            $table->dropIndex(['incident_status', 'incident_date']);
            $table->dropIndex(['severity', 'incident_date']);
            $table->dropIndex(['incident_type', 'incident_date']);
        });

        Schema::table('action_improvements', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
            $table->dropIndex(['status']);
            $table->dropIndex(['incident_id']);
        });

        Schema::table('status_updates', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['incident_id', 'created_at']);
        });

        Schema::table('investigation_documents', function (Blueprint $table) {
            $table->dropIndex(['incident_id']);
        });
    }
};
