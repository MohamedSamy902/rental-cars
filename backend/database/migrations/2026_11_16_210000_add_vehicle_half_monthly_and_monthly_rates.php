<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أسعار الباقات على العربية: يومي · نص شهري (١٥ يوماً) · شهري (٣٠ يوماً).
 * تُستخدم كافتراضي عند إنشاء العقد وتظهر في المعرض.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'half_monthly_rate')) {
                $table->unsignedInteger('half_monthly_rate')->nullable()->after('daily_rate');
            }
            if (! Schema::hasColumn('vehicles', 'monthly_rate')) {
                $after = Schema::hasColumn('vehicles', 'half_monthly_rate') ? 'half_monthly_rate' : 'daily_rate';
                $table->unsignedInteger('monthly_rate')->nullable()->after($after);
            }
        });

        // تعبئة الصفوف الحالية من اليومي × العوامل الافتراضية.
        if (Schema::hasColumn('vehicles', 'half_monthly_rate') && Schema::hasColumn('vehicles', 'monthly_rate')) {
            DB::table('vehicles')->whereNull('half_monthly_rate')->update([
                'half_monthly_rate' => DB::raw('ROUND(daily_rate * 15)'),
            ]);
            DB::table('vehicles')->whereNull('monthly_rate')->update([
                'monthly_rate' => DB::raw('ROUND(daily_rate * 22)'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            foreach (['half_monthly_rate', 'monthly_rate'] as $col) {
                if (Schema::hasColumn('vehicles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
