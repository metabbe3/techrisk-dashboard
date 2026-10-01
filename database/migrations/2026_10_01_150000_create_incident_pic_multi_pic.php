<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-PIC cutover (PROJ-010): incidents.pic_id (single) becomes the
 * incident_pic pivot (many). Atomic — the single column is copied into the
 * pivot and dropped in the same migration, so no dual-write drift window.
 *
 * Prod run is an owner-only guardrail (drops pic_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_pic')) {
            Schema::create('incident_pic', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unique(['incident_id', 'user_id']);
                $table->timestamps();
            });
        }

        if (Schema::hasColumn('incidents', 'pic_id')) {
            DB::table('incident_pic')->insertUsing(
                ['incident_id', 'user_id', 'created_at', 'updated_at'],
                DB::table('incidents')
                    ->whereNotNull('pic_id')
                    ->select('id', 'pic_id', DB::raw('CURRENT_TIMESTAMP'), DB::raw('CURRENT_TIMESTAMP'))
            );

            Schema::table('incidents', function (Blueprint $table): void {
                $table->dropForeign(['pic_id']);
                $table->dropIndex(['pic_id']);
                $table->dropColumn('pic_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->foreignId('pic_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // Restore one PIC per incident (first attached wins) — portable loop,
        // UPDATE..JOIN syntax differs between MySQL and sqlite.
        DB::table('incident_pic')
            ->selectRaw('incident_id, MIN(id) as pivot_id')
            ->groupBy('incident_id')
            ->get()
            ->each(function ($row): void {
                DB::table('incident_pic')->whereKey($row->pivot_id)->pluck('user_id')->each(
                    fn ($userId) => DB::table('incidents')->where('id', $row->incident_id)->update(['pic_id' => $userId])
                );
            });

        Schema::dropIfExists('incident_pic');
    }
};
