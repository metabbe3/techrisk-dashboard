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
        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('fund_status', ['Non fundLoss', 'Confirmed loss', 'Potential recovery', 'Fully recovered'])->nullable()->change();
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->decimal('mtbf_fully_recovered', 10, 2)->nullable()->after('mtbf_potential_recovery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('mtbf_fully_recovered');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('fund_status', ['Non fundLoss', 'Confirmed loss', 'Potential recovery'])->nullable()->change();
        });
    }
};
