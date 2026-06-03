<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->uuid('plan_id')->nullable()->after('attachments');
            $table->json('plan_metadata')->nullable()->after('plan_id');
            $table->boolean('is_plan_message')->default(false)->after('plan_metadata');
            $table->string('plan_role', 30)->nullable()->after('is_plan_message');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['plan_id', 'plan_metadata', 'is_plan_message', 'plan_role']);
        });
    }
};
