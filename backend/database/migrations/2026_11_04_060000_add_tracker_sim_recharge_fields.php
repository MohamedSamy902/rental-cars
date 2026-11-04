<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('tracker_sim_last_recharged_on')->nullable()->after('tracker_sim_number');
            $table->date('tracker_sim_next_recharge_on')->nullable()->after('tracker_sim_last_recharged_on');
            $table->unsignedSmallInteger('tracker_sim_recharge_cycle_days')->nullable()->default(30)->after('tracker_sim_next_recharge_on');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'tracker_sim_last_recharged_on',
                'tracker_sim_next_recharge_on',
                'tracker_sim_recharge_cycle_days',
            ]);
        });
    }
};
