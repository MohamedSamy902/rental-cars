<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'include_vehicle_value_obligation')) {
                $table->boolean('include_vehicle_value_obligation')->default(false)->after('frozen_operating_insurance_fee');
            }
            if (! Schema::hasColumn('contracts', 'frozen_vehicle_value_text')) {
                $table->string('frozen_vehicle_value_text', 500)->nullable()->after('include_vehicle_value_obligation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'frozen_vehicle_value_text')) {
                $table->dropColumn('frozen_vehicle_value_text');
            }
            if (Schema::hasColumn('contracts', 'include_vehicle_value_obligation')) {
                $table->dropColumn('include_vehicle_value_obligation');
            }
        });
    }
};
