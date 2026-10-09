<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('email_incident_not_done_reminder')->default(true);
            $table->boolean('database_incident_not_done_reminder')->default(true);
            $table->boolean('email_fund_loss_unsettled_reminder')->default(true);
            $table->boolean('database_fund_loss_unsettled_reminder')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'email_incident_not_done_reminder',
                'database_incident_not_done_reminder',
                'email_fund_loss_unsettled_reminder',
                'database_fund_loss_unsettled_reminder',
            ]);
        });
    }
};
