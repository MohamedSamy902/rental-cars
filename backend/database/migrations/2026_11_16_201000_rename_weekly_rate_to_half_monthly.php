<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** إن وُجد عمود الأسبوعي من إصدار سابق يُستبدل بنص الشهري. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'frozen_weekly_rate')) {
            return;
        }

        if (! Schema::hasColumn('contracts', 'frozen_half_monthly_rate')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->renameColumn('frozen_weekly_rate', 'frozen_half_monthly_rate');
            });

            return;
        }

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('frozen_weekly_rate');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('contracts', 'frozen_half_monthly_rate') && ! Schema::hasColumn('contracts', 'frozen_weekly_rate')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->renameColumn('frozen_half_monthly_rate', 'frozen_weekly_rate');
            });
        }
    }
};
