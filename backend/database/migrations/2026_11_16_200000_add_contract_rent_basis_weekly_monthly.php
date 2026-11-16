<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إيجار يومي / أسبوعي / شهري على العقد.
 * الأيام الزيادة فوق الأسبوع أو الشهر تُحاسب بنفس تكلفة يوم الوحدة (سعر الباقة ÷ أيام الوحدة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('rent_basis', 16)->default('daily')->after('frozen_daily_rate');
            $table->unsignedInteger('frozen_weekly_rate')->nullable()->after('rent_basis');
            $table->unsignedInteger('frozen_monthly_rate')->nullable()->after('frozen_weekly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['rent_basis', 'frozen_weekly_rate', 'frozen_monthly_rate']);
        });
    }
};
