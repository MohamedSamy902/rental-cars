<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'credit_balance')) {
                $table->unsignedInteger('credit_balance')->default(0)->after('notes');
            }
        });

        Schema::create('customer_credit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16); // credit | debit
            $table->unsignedInteger('amount');
            $table->string('reason', 64); // early_termination | cancellation | manual | contract_payment | adjustment
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_vehicle_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('to_vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->unsignedInteger('segment_start_odometer'); // عداد خروج القديمة عند بداية حيازتها
            $table->unsignedInteger('odometer_in'); // عداد القديمة عند الإرجاع للمعرض
            $table->unsignedInteger('odometer_out'); // عداد البديلة عند الخروج للعميل
            $table->string('reason')->default('عطل');
            $table->text('fault_description')->nullable();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('swapped_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_vehicle_swaps');
        Schema::dropIfExists('customer_credit_movements');
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'credit_balance')) {
                $table->dropColumn('credit_balance');
            }
        });
    }
};
