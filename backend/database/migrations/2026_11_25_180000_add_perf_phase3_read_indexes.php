<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BE-7: فهارس قراءة فقط — بلا تعديل صفوف أعمال (ك٫١).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['status', 'starts_at'], 'contracts_status_starts_at_idx');
            $table->index(['status', 'planned_ends_at'], 'contracts_status_planned_ends_at_idx');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->index(['vehicle_id', 'status', 'puts_in_maintenance'], 'work_orders_vehicle_status_maint_idx');
            $table->index(['status', 'puts_in_maintenance'], 'work_orders_status_maint_idx');
            $table->index(['opened_on'], 'work_orders_opened_on_idx');
            $table->index(['closed_on'], 'work_orders_closed_on_idx');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['vehicle_id', 'spent_on'], 'expenses_vehicle_spent_on_idx');
            $table->index(['spent_on'], 'expenses_spent_on_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['paid_at'], 'payments_paid_at_idx');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['entry_date'], 'journal_entries_entry_date_idx');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->index(['vehicle_id'], 'journal_lines_vehicle_id_idx');
            $table->index(['account_id', 'vehicle_id'], 'journal_lines_account_vehicle_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'read_at'], 'notifications_user_read_at_idx');
        });

        Schema::table('violations', function (Blueprint $table) {
            $table->index(['status'], 'violations_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_status_starts_at_idx');
            $table->dropIndex('contracts_status_planned_ends_at_idx');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex('work_orders_vehicle_status_maint_idx');
            $table->dropIndex('work_orders_status_maint_idx');
            $table->dropIndex('work_orders_opened_on_idx');
            $table->dropIndex('work_orders_closed_on_idx');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_vehicle_spent_on_idx');
            $table->dropIndex('expenses_spent_on_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_paid_at_idx');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('journal_entries_entry_date_idx');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex('journal_lines_vehicle_id_idx');
            $table->dropIndex('journal_lines_account_vehicle_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_read_at_idx');
        });

        Schema::table('violations', function (Blueprint $table) {
            $table->dropIndex('violations_status_idx');
        });
    }
};
