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
        Schema::table('incidents', function (Blueprint $table) {
            $table->integer('mtbf_completed')->after('mtbf')->nullable()->comment('MTBF for completed incidents only');
            $table->integer('mtbf_recovered')->after('mtbf_completed')->nullable()->comment('MTBF for incidents with recovered_fund > 0');
            $table->integer('mtbf_p4')->after('mtbf_recovered')->nullable()->comment('MTBF for P4 severity incidents only');
            $table->integer('mtbf_non_tech')->after('mtbf_p4')->nullable()->comment('MTBF for non-tech incidents only');
            $table->integer('mtbf_fund_loss')->after('mtbf_non_tech')->nullable()->comment('MTBF for confirmed loss incidents only');
            $table->integer('mtbf_non_fund_loss')->after('mtbf_fund_loss')->nullable()->comment('MTBF for non-fund loss incidents only');
            $table->integer('mtbf_potential_recovery')->after('mtbf_non_fund_loss')->nullable()->comment('MTBF for potential recovery incidents only');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn([
                'mtbf_completed',
                'mtbf_recovered',
                'mtbf_p4',
                'mtbf_non_tech',
                'mtbf_fund_loss',
                'mtbf_non_fund_loss',
                'mtbf_potential_recovery',
            ]);
        });
    }
};
