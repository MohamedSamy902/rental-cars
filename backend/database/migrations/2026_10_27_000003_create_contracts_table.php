<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('guarantor_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->dateTime('starts_at');
            $table->dateTime('planned_ends_at');
            $table->dateTime('actual_ends_at')->nullable();
            $table->unsignedInteger('approved_days')->nullable();
            $table->unsignedInteger('min_days')->nullable();
            $table->unsignedInteger('frozen_daily_rate')->nullable();
            $table->unsignedInteger('frozen_daily_km_allowance')->nullable();
            $table->unsignedInteger('frozen_excess_km_rate')->nullable();
            $table->unsignedInteger('frozen_agreed_deposit')->nullable();
            $table->unsignedInteger('frozen_operating_insurance_fee')->nullable();
            $table->unsignedInteger('frozen_min_days')->nullable();
            $table->unsignedInteger('tagged_late_days')->default(0);
            $table->unsignedInteger('odometer_out')->nullable();
            $table->unsignedInteger('odometer_in')->nullable();
            $table->unsignedInteger('allowed_km')->nullable();
            $table->unsignedInteger('excess_km')->nullable();
            $table->unsignedInteger('excess_km_amount')->nullable();
            $table->unsignedInteger('period_rent')->nullable();
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('damage_fee')->default(0);
            $table->unsignedInteger('other_fees')->default(0);
            $table->string('other_fees_description')->nullable();
            $table->unsignedInteger('final_total')->nullable();
            $table->foreignId('extended_from_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('swapped_from_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('swap_reason')->nullable();
            $table->foreignId('sales_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('branch_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'status', 'starts_at', 'planned_ends_at']);
            $table->index(['customer_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
