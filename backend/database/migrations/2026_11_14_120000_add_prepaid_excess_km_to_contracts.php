<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedInteger('prepaid_excess_km')->default(0)->after('frozen_excess_km_rate');
            $table->unsignedInteger('prepaid_excess_km_amount')->default(0)->after('prepaid_excess_km');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['prepaid_excess_km', 'prepaid_excess_km_amount']);
        });
    }
};
