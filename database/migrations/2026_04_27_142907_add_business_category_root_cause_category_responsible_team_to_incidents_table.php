<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->json('business_category')->nullable()->after('incident_category');
            $table->json('root_cause_category')->nullable()->after('root_cause');
            $table->json('responsible_team')->nullable()->after('reported_by');
        });

        // Modify incident_type enum to add 'Company Loss'
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE incidents MODIFY COLUMN incident_type ENUM('Tech', 'Non-tech', 'Company Loss') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['business_category', 'root_cause_category', 'responsible_team']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE incidents MODIFY COLUMN incident_type ENUM('Tech', 'Non-tech') NOT NULL");
        }
    }
};
