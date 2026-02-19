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
            // Add foreign key to incident_types table
            $table->foreignId('incident_type_id')->nullable()->after('incident_category')->constrained('incident_types')->onDelete('set null');
        });

        // Update severity enum to include X1, X2, X3, X4
        // Note: MySQL doesn't support enum modification directly, need to recreate
        // SQLite doesn't support ENUM, so we skip this for SQLite (it treats enums as TEXT)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE incidents MODIFY COLUMN severity ENUM('P1', 'P2', 'P3', 'P4', 'G', 'X1', 'X2', 'X3', 'X4', 'Non Incident') DEFAULT 'P1'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['incident_type_id']);
            $table->dropColumn('incident_type_id');
        });

        // Revert severity enum
        // SQLite doesn't support ENUM, so we skip this for SQLite
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE incidents MODIFY COLUMN severity ENUM('P1', 'P2', 'P3', 'P4', 'Non Incident') DEFAULT 'P1'");
        }
    }
};
