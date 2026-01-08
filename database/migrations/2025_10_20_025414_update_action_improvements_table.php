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
            $table->text('pic_email')->change();
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->string('pic_email')->change();
            $table->dropColumn(['email_subject', 'email_body']);
        });
    }
};
