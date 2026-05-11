<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('persona_key', 50)->nullable()->after('model');
            $table->string('persona_name', 100)->nullable()->after('persona_key');
            $table->string('persona_icon', 100)->nullable()->after('persona_name');
            $table->string('persona_color', 50)->nullable()->after('persona_icon');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['persona_key', 'persona_name', 'persona_icon', 'persona_color']);
        });
    }
};
