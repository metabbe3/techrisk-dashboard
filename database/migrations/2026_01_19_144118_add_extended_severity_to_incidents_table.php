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
            $table->enum('severity', ['P1', 'P2', 'P3', 'P4', 'G', 'X1', 'X2', 'X3', 'X4', 'Non Incident'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('severity', ['P1', 'P2', 'P3', 'P4', 'Non Incident'])->change();
        });
    }
};
