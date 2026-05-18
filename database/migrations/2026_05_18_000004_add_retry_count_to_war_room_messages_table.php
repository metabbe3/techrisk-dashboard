<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('war_room_messages', function (Blueprint $table) {
            $table->unsignedInteger('retry_count')->default(0)->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('war_room_messages', function (Blueprint $table) {
            $table->dropColumn('retry_count');
        });
    }
};
