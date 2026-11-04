<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salary advances logged during the month, plus partial payouts with rollover of the unpaid remainder.
     */
    public function up(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->unsignedInteger('carried_in')->default(0)->after('deductions');
            $table->unsignedInteger('advances')->default(0)->after('carried_in');
            $table->unsignedInteger('paid_amount')->default(0)->after('net_amount');
            $table->unsignedInteger('carried_over')->default(0)->after('paid_amount');
        });

        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->date('advanced_on');
            $table->string('method')->default('cash');
            $table->foreignId('payroll_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'advanced_on']);
        });

        Schema::create('payroll_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_line_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->date('paid_on');
            $table->string('method')->default('cash');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('paid_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payouts');
        Schema::dropIfExists('salary_advances');
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn(['carried_in', 'advances', 'paid_amount', 'carried_over']);
        });
    }
};
