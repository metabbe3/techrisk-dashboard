<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('email_critical_incident')->default(true);
            $table->boolean('database_critical_incident')->default(true);
            $table->boolean('email_action_improvement_assigned')->default(true);
            $table->boolean('database_action_improvement_assigned')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'email_critical_incident',
                'database_critical_incident',
                'email_action_improvement_assigned',
                'database_action_improvement_assigned',
            ]);
        });
    }
};
