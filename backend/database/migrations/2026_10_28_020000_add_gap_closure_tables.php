<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'national_id_image_path')) {
                $table->string('national_id_image_path')->nullable()->after('national_id_expires_on');
            }
            if (! Schema::hasColumn('customers', 'license_image_path')) {
                $table->string('license_image_path')->nullable()->after('license_expires_on');
            }
        });

        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('category')->default('تشغيل');
                $table->unsignedInteger('amount');
                $table->date('spent_on');
                $table->string('payment_method')->default('cash');
                $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
                $table->boolean('from_petty_cash')->default(false);
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->string('status')->default('draft'); // draft|approved
                $table->unsignedInteger('total_amount')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['year', 'month']);
            });
        }

        if (! Schema::hasTable('payroll_lines')) {
            Schema::create('payroll_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('base_salary')->default(0);
                $table->unsignedInteger('allowances')->default(0);
                $table->unsignedInteger('deductions')->default(0);
                $table->unsignedInteger('net_amount')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('print_snapshots')) {
            Schema::create('print_snapshots', function (Blueprint $table) {
                $table->id();
                $table->string('type'); // contract|invoice|receipt|handover|department_notice
                $table->string('record_type');
                $table->unsignedBigInteger('record_id');
                $table->json('payload');
                $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('printed_at');
                $table->timestamps();
                $table->index(['record_type', 'record_id']);
            });
        }

        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('channel')->default('ops');
                $table->text('body');
                $table->timestamps();
                $table->index(['channel', 'created_at']);
            });
        }

        if (! Schema::hasTable('evaluations')) {
            Schema::create('evaluations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedTinyInteger('score'); // 1-5
                $table->string('period')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('print_snapshots');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('expenses');

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'national_id_image_path')) {
                $table->dropColumn('national_id_image_path');
            }
            if (Schema::hasColumn('customers', 'license_image_path')) {
                $table->dropColumn('license_image_path');
            }
        });
    }
};
