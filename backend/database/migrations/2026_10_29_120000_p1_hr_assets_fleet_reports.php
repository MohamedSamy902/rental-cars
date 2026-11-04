<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_assignments')) {
            Schema::create('employee_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('assignable_type', 80);
                $table->unsignedBigInteger('assignable_id')->nullable();
                $table->string('title');
                $table->text('notes')->nullable();
                $table->string('status', 32)->default('pending_ack');
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamps();
                $table->index(['employee_id', 'status']);
                $table->index(['assignable_type', 'assignable_id']);
            });
        }

        if (! Schema::hasTable('vehicle_tires')) {
            Schema::create('vehicle_tires', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->string('position', 32);
                $table->string('brand')->nullable();
                $table->date('installed_on');
                $table->unsignedInteger('odometer_at_install');
                $table->unsignedInteger('expected_life_km');
                $table->date('replaced_on')->nullable();
                $table->unsignedInteger('odometer_at_replace')->nullable();
                $table->unsignedInteger('cost')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['vehicle_id', 'position']);
            });
        }

        if (! Schema::hasTable('vehicle_batteries')) {
            Schema::create('vehicle_batteries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->string('brand')->nullable();
                $table->date('installed_on');
                $table->unsignedInteger('odometer_at_install');
                $table->unsignedInteger('expected_life_km');
                $table->date('replaced_on')->nullable();
                $table->unsignedInteger('odometer_at_replace')->nullable();
                $table->unsignedInteger('cost')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index('vehicle_id');
            });
        }

        if (! Schema::hasTable('vehicle_downtime_days')) {
            Schema::create('vehicle_downtime_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->string('reason', 40);
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->nullable();
                $table->decimal('days_count', 8, 2)->nullable();
                $table->unsignedInteger('lost_revenue_estimate')->default(0);
                $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['vehicle_id', 'starts_at']);
            });
        }

        if (! Schema::hasTable('consumable_part_templates')) {
            Schema::create('consumable_part_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('category')->nullable();
                $table->unsignedInteger('default_life_km')->nullable();
                $table->unsignedInteger('default_life_days')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicle_consumable_states')) {
            Schema::create('vehicle_consumable_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->foreignId('template_id')->constrained('consumable_part_templates')->cascadeOnDelete();
                $table->date('installed_on')->nullable();
                $table->unsignedInteger('odometer_at_install')->nullable();
                $table->unsignedInteger('life_km')->nullable();
                $table->unsignedInteger('life_days')->nullable();
                $table->date('due_on')->nullable();
                $table->unsignedInteger('due_odometer')->nullable();
                $table->string('status', 32)->default('ok');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['vehicle_id', 'template_id']);
            });
        }

        if (! Schema::hasTable('salary_advances')) {
            Schema::create('salary_advances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->unsignedInteger('amount');
                $table->date('advanced_on');
                $table->string('method', 32)->default('cash');
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['employee_id', 'year', 'month']);
            });
        } else {
            Schema::table('salary_advances', function (Blueprint $table) {
                if (! Schema::hasColumn('salary_advances', 'year')) {
                    $table->unsignedSmallInteger('year')->nullable()->after('method');
                }
                if (! Schema::hasColumn('salary_advances', 'month')) {
                    $table->unsignedTinyInteger('month')->nullable()->after('year');
                }
                if (! Schema::hasColumn('salary_advances', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advances');
        Schema::dropIfExists('vehicle_consumable_states');
        Schema::dropIfExists('consumable_part_templates');
        Schema::dropIfExists('vehicle_downtime_days');
        Schema::dropIfExists('vehicle_batteries');
        Schema::dropIfExists('vehicle_tires');
        Schema::dropIfExists('employee_assignments');
    }
};
