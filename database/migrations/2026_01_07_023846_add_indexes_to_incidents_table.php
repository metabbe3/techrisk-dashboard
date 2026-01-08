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
            $table->index('incident_date');
            $table->index('fund_loss');
            $table->index('potential_fund_loss');
            $table->index('incident_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['incident_date']);
            $table->dropIndex(['fund_loss']);
            $table->dropIndex(['potential_fund_loss']);
            $table->dropIndex(['incident_type']);
        });
    }
};
