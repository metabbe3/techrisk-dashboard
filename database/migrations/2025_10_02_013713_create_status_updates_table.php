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
        Schema::create('status_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->onDelete('cascade');
            $table->string('status');
            $table->text('notes')->nullable();
            $table->timestamps(); // The 'created_at' column will be our automatic update date!
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_updates');
    }
};
