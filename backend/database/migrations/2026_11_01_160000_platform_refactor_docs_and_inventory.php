<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'national_id_back_path')) {
                $table->string('national_id_back_path')->nullable()->after('national_id_image_path');
            }
            if (! Schema::hasColumn('customers', 'license_back_path')) {
                $table->string('license_back_path')->nullable()->after('license_image_path');
            }
        });

        Schema::table('vehicle_owners', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicle_owners', 'license_front_path')) {
                $table->string('license_front_path')->nullable()->after('id_back_path');
            }
            if (! Schema::hasColumn('vehicle_owners', 'license_back_path')) {
                $table->string('license_back_path')->nullable()->after('license_front_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            foreach (['national_id_back_path', 'license_back_path'] as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('vehicle_owners', function (Blueprint $table) {
            foreach (['license_front_path', 'license_back_path'] as $col) {
                if (Schema::hasColumn('vehicle_owners', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
