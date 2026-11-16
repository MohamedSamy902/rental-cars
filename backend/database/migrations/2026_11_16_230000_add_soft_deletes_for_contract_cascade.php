<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SoftDeletes للكيانات المرتبطة بالعقد حتى يحذف السوبر أدمن العقد ناعماً مع مالياته.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'payments',
        'deposits',
        'deposit_deductions',
        'inspections',
        'inspection_damages',
        'journal_entries',
        'journal_lines',
        'contract_vehicle_swaps',
        'customer_credit_movements',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
