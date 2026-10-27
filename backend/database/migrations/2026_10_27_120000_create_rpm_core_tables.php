<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('module');
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->foreignId('role_id')->nullable()->after('password')->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->after('role_id');
            $table->boolean('is_active')->default(true)->after('employee_id');
            $table->unsignedInteger('session_minutes')->nullable()->after('is_active');
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->string('center_name')->default('مركز التأجير');
            $table->string('logo_path')->nullable();
            $table->json('colors')->nullable();
            $table->boolean('multi_branch')->default(false);
            $table->string('inventory_mode')->default('unified');
            $table->json('feature_flags')->nullable();
            $table->json('operational')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicle_accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('default_qty')->default(1);
            $table->boolean('required_each_rental')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('file_path');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('waitlist_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('category');
            $table->foreignId('preferred_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->date('desired_start')->nullable();
            $table->date('desired_end')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->string('status')->default('waiting');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('resulting_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->dateTime('inspected_at');
            $table->unsignedInteger('odometer');
            $table->unsignedTinyInteger('fuel_level');
            $table->text('notes')->nullable();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signature_path')->nullable();
            $table->timestamps();
        });

        Schema::create('inspection_damages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('position');
            $table->string('description');
            $table->string('photo_path')->nullable();
            $table->boolean('present_at_checkout')->default(false);
            $table->timestamps();
        });

        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('original_amount');
            $table->string('status')->default('received');
            $table->dateTime('received_at');
            $table->string('receipt_number');
            $table->string('collection_method');
            $table->string('collection_ref')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->string('return_receipt_number')->nullable();
            $table->string('return_method')->nullable();
            $table->unsignedInteger('deductions_total')->default(0);
            $table->timestamps();
        });

        Schema::create('deposit_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->unsignedInteger('amount');
            $table->foreignId('damage_id')->nullable();
            $table->foreignId('violation_id')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->dateTime('deducted_at');
            $table->string('attachment_path')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('amount');
            $table->dateTime('paid_at');
            $table->string('method');
            $table->string('direction');
            $table->string('reference')->nullable();
            $table->string('link_type');
            $table->unsignedBigInteger('link_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['link_type', 'link_id']);
        });

        Schema::create('violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained();
            $table->dateTime('occurred_at');
            $table->string('location')->nullable();
            $table->unsignedInteger('amount');
            $table->string('photo_path')->nullable();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('new');
            $table->text('collection_note')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('unit');
            $table->unsignedInteger('reorder_level')->default(0);
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('branch_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->date('received_on');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('remaining');
            $table->unsignedInteger('unit_cost');
            $table->string('supplier')->nullable();
            $table->string('payment_method')->nullable();
            $table->boolean('from_petty_cash')->default(false);
            $table->foreignId('petty_cash_employee_id')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['inventory_item_id', 'received_on']);
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained();
            $table->string('workshop')->nullable();
            $table->date('opened_on');
            $table->date('closed_on')->nullable();
            $table->unsignedInteger('odometer');
            $table->string('status')->default('open');
            $table->boolean('puts_in_maintenance')->default(true);
            $table->unsignedInteger('parts_cost')->default(0);
            $table->unsignedInteger('labor_cost')->default(0);
            $table->string('invoice_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('work_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('part_source');
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price')->default(0);
            $table->boolean('from_petty_cash')->default(false);
            $table->foreignId('petty_cash_employee_id')->nullable();
            $table->unsignedInteger('petty_cash_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->date('hired_on')->nullable();
            $table->unsignedInteger('base_salary')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('petty_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('limit_amount');
            $table->unsignedInteger('balance')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('petty_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_account_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('amount');
            $table->date('moved_on');
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->string('narration');
            $table->string('source');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->unsignedInteger('debit')->default(0);
            $table->unsignedInteger('credit')->default(0);
            $table->foreignId('vehicle_id')->nullable();
            $table->foreignId('customer_id')->nullable();
            $table->timestamps();
        });

        Schema::create('month_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('link_type')->nullable();
            $table->unsignedBigInteger('link_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('record_type');
            $table->unsignedBigInteger('record_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        $tables = [
            'audit_logs', 'notifications', 'month_locks', 'journal_lines', 'journal_entries', 'accounts',
            'petty_cash_movements', 'petty_cash_accounts', 'employees', 'work_order_lines', 'work_orders',
            'inventory_batches', 'inventory_items', 'violations', 'payments', 'deposit_deductions', 'deposits',
            'inspection_damages', 'inspections', 'waitlist_requests', 'vehicle_documents', 'vehicle_accessories',
            'branches', 'tenant_settings', 'permission_role', 'permissions', 'roles',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
