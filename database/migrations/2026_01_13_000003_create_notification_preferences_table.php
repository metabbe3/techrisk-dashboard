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
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Email notification preferences
            $table->boolean('email_incident_assignment')->default(true);
            $table->boolean('email_incident_update')->default(true);
            $table->boolean('email_incident_status_changed')->default(true);
            $table->boolean('email_status_update')->default(true);
            $table->boolean('email_action_improvement_reminder')->default(true);
            $table->boolean('email_action_improvement_overdue')->default(true);

            // Database notification preferences
            $table->boolean('database_incident_assignment')->default(true);
            $table->boolean('database_incident_update')->default(true);
            $table->boolean('database_incident_status_changed')->default(true);
            $table->boolean('database_status_update')->default(true);
            $table->boolean('database_action_improvement_reminder')->default(true);
            $table->boolean('database_action_improvement_overdue')->default(true);

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
