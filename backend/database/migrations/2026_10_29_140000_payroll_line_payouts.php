<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_lines')) {
            Schema::table('payroll_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('payroll_lines', 'carried_in')) {
                    $table->unsignedInteger('carried_in')->default(0)->after('deductions');
                }
                if (! Schema::hasColumn('payroll_lines', 'advances')) {
                    $table->unsignedInteger('advances')->default(0)->after('carried_in');
                }
                if (! Schema::hasColumn('payroll_lines', 'paid_amount')) {
                    $table->unsignedInteger('paid_amount')->default(0)->after('net_amount');
                }
                if (! Schema::hasColumn('payroll_lines', 'carried_over')) {
                    $table->unsignedInteger('carried_over')->default(0)->after('paid_amount');
                }
            });
        }

        if (! Schema::hasTable('payroll_line_payouts')) {
            Schema::create('payroll_line_payouts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_line_id')->constrained('payroll_lines')->cascadeOnDelete();
                $table->unsignedInteger('amount');
                $table->date('paid_on');
                $table->string('method', 32)->default('cash');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['payroll_line_id', 'paid_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_line_payouts');
    }
};
