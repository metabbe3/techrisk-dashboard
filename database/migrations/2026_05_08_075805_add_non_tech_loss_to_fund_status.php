<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('fund_status', ['Non fundLoss', 'Confirmed loss', 'Potential recovery', 'Fully recovered', 'Non Tech Loss'])->nullable()->change();
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->decimal('mtbf_non_tech_loss', 10, 2)->nullable()->after('mtbf_fully_recovered');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('mtbf_non_tech_loss');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('fund_status', ['Non fundLoss', 'Confirmed loss', 'Potential recovery', 'Fully recovered'])->nullable()->change();
        });
    }
};
