<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Investigation form intake (owner 2026-11-16): per incident+team, the admin
// emails each recipient a unique no-login link; the team shares one draft and
// one submission renders DOCX+PDF onto the incident's Supporting Documents.
// Plain tokens are never stored — sha256 hash only.
return new class extends Migration
{
    public function up(): void
    {
        // ponytail: explicit short FK/unique names — MySQL caps identifiers
        // at 64 chars, SQLite (tests) doesn't care but prod does.
        Schema::create('investigation_form_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')
                ->constrained('incidents', 'id', 'ifr_incident_id_foreign')
                ->onDelete('cascade');
            $table->string('team');
            $table->dateTime('expires_at');
            $table->string('status')->default('pending'); // pending|submitted|revoked
            $table->foreignId('created_by')->nullable()
                ->constrained('users', 'id', 'ifr_created_by_foreign')
                ->nullOnDelete();
            $table->json('answers')->nullable();
            $table->string('draft_updated_by_email')->nullable();
            // Millisecond precision — the intake's concurrency guard compares
            // draft_updated_at at sub-second resolution ('U.u').
            $table->dateTime('draft_updated_at', 3)->nullable();
            $table->string('submitter_email')->nullable();
            $table->string('submitter_name')->nullable();
            $table->dateTime('consented_at')->nullable();
            $table->string('submitter_ip')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('docx_document_id')->nullable()
                ->constrained('investigation_documents', 'id', 'ifr_docx_document_id_foreign')
                ->nullOnDelete();
            $table->foreignId('pdf_document_id')->nullable()
                ->constrained('investigation_documents', 'id', 'ifr_pdf_document_id_foreign')
                ->nullOnDelete();
            $table->timestamps();
            $table->unique(['incident_id', 'team'], 'ifr_incident_team_unique');
        });

        Schema::create('investigation_form_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')
                ->constrained('investigation_form_requests', 'id', 'ifrec_request_id_foreign')
                ->onDelete('cascade');
            $table->string('email');
            $table->string('token_hash')->unique();
            $table->dateTime('emailed_at')->nullable();
            $table->dateTime('last_opened_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_reminded_at')->nullable();
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamps();
            $table->unique(['request_id', 'email'], 'ifrec_request_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_form_recipients');
        Schema::dropIfExists('investigation_form_requests');
    }
};
