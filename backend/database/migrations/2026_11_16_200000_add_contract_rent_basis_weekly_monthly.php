<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أسعار الإيجار على العقد: يومي · نص شهري (١٥ يوماً) · شهري (٣٠ يوماً).
 * الشريحة تُحدَّد من مدة العقد: ١–١٠ يومي · ١١–٢٠ نص شهري · ٢١+ شهري.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'rent_basis')) {
                $table->string('rent_basis', 16)->default('daily')->after('frozen_daily_rate');
            }
            if (! Schema::hasColumn('contracts', 'frozen_half_monthly_rate')) {
                $after = Schema::hasColumn('contracts', 'rent_basis') ? 'rent_basis' : 'frozen_daily_rate';
                $table->unsignedInteger('frozen_half_monthly_rate')->nullable()->after($after);
            }
            if (! Schema::hasColumn('contracts', 'frozen_monthly_rate')) {
                $after = Schema::hasColumn('contracts', 'frozen_half_monthly_rate')
                    ? 'frozen_half_monthly_rate'
                    : (Schema::hasColumn('contracts', 'rent_basis') ? 'rent_basis' : 'frozen_daily_rate');
                $table->unsignedInteger('frozen_monthly_rate')->nullable()->after($after);
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            foreach (['rent_basis', 'frozen_half_monthly_rate', 'frozen_monthly_rate'] as $col) {
                if (Schema::hasColumn('contracts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
