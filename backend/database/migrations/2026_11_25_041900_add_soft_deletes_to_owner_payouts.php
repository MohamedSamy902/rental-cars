<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذف صرف المالك = soft-delete + عكس قيد اليومية (ك٫١ — بلا مسح صامت للسجلات).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('owner_payouts')) {
            return;
        }
        if (Schema::hasColumn('owner_payouts', 'deleted_at')) {
            return;
        }
        Schema::table('owner_payouts', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('owner_payouts') || ! Schema::hasColumn('owner_payouts', 'deleted_at')) {
            return;
        }
        Schema::table('owner_payouts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
