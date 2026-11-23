<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red-team fix W5 (2026-11-21): SSO identity was matched on email (= Entra
 * UPN) only — a tenant-admin UPN rename silently took over the matching
 * local account. The callback now binds on the Entra OBJECT ID first
 * (azure_id) and falls back to email, stamping the id on first match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('azure_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['azure_id']);
            $table->dropColumn('azure_id');
        });
    }
};
