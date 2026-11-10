<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'tracker_sim_last_recharge_amount')) {
                $table->unsignedInteger('tracker_sim_last_recharge_amount')->nullable()
                    ->after('tracker_sim_last_recharged_on');
            }
        });

        if (! Schema::hasTable('vehicle_tracker_sim_recharges')) {
            Schema::create('vehicle_tracker_sim_recharges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->unsignedInteger('amount');
                $table->date('recharged_on');
                $table->date('next_recharge_on');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->index(['vehicle_id', 'recharged_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_tracker_sim_recharges');
        Schema::table('vehicles', function (Blueprint $table) {
            if (Schema::hasColumn('vehicles', 'tracker_sim_last_recharge_amount')) {
                $table->dropColumn('tracker_sim_last_recharge_amount');
            }
        });
    }
};
