<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('plate')->unique();
            $table->string('chassis_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->string('brand');
            $table->string('model');
            $table->unsignedSmallInteger('year');
            $table->string('color')->nullable();
            $table->string('category');
            $table->string('engine_capacity')->nullable();
            $table->string('transmission')->nullable();
            $table->string('fuel_type');
            $table->unsignedTinyInteger('seats')->nullable();
            $table->unsignedInteger('odometer');
            $table->string('odometer_source')->default('manual');
            $table->unsignedInteger('daily_rate');
            $table->unsignedInteger('default_deposit');
            $table->unsignedInteger('target_monthly_income')->nullable();
            $table->unsignedInteger('purchase_value_estimate')->nullable();
            $table->string('tracker_device_number')->nullable();
            $table->string('tracker_sim_number')->nullable();
            $table->unsignedTinyInteger('rental_priority')->default(50);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('manual_status')->nullable();
            $table->foreignId('branch_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'is_active']);
            $table->index('manual_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
