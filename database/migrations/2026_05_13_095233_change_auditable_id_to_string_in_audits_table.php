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
        Schema::table('audits', function (Blueprint $table) {
            $table->string('auditable_id', 36)->change();
            $table->string('user_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->bigInteger('auditable_id')->change();
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }
};
