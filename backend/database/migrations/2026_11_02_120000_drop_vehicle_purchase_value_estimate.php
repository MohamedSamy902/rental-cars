<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'purchase_value_estimate')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropColumn('purchase_value_estimate');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicles') && ! Schema::hasColumn('vehicles', 'purchase_value_estimate')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->unsignedInteger('purchase_value_estimate')->nullable()->after('target_monthly_income');
            });
        }
    }
};
