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
        Schema::create('user_dashboard_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('widget_class'); // e.g., 'App\Filament\Widgets\MonthlyIncidentsChart'
            $table->boolean('is_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('column_span')->nullable(); // Custom column span per user
            $table->timestamps();

            $table->unique(['user_id', 'widget_class']);
            $table->index(['user_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_dashboard_preferences');
    }
};
