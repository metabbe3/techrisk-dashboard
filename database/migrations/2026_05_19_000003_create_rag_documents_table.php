<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('incident_no')->index();

            $table->string('severity', 10)->nullable();
            $table->string('classification', 20)->nullable();
            $table->string('incident_status', 30)->nullable();
            $table->string('incident_type', 30)->nullable();
            $table->date('incident_date')->nullable();
            $table->string('fund_status', 30)->nullable();
            $table->decimal('fund_loss', 15, 2)->default(0);
            $table->decimal('potential_fund_loss', 15, 2)->default(0);
            $table->unsignedBigInteger('pic_id')->nullable();
            $table->json('business_category')->nullable();
            $table->json('root_cause_category')->nullable();
            $table->json('responsible_team')->nullable();
            $table->json('label_names')->nullable();

            $table->text('searchable_content');
            $table->text('context_content')->nullable();

            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText(['searchable_content'], 'rag_documents_search_fulltext');
            }
            $table->index(['severity', 'incident_date']);
            $table->index(['classification', 'incident_date']);
            $table->index(['incident_status', 'incident_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_documents');
    }
};
