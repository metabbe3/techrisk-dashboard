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
        Schema::table('action_improvements', function (Blueprint $table) {
            // Change pic_email from string to json
            $table->json('pic_email')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->string('pic_email')->change();
        });
    }
};
