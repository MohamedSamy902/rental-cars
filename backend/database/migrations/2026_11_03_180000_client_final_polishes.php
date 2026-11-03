<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inspections') && Schema::hasColumn('inspections', 'fuel_level')) {
            Schema::table('inspections', function (Blueprint $table) {
                $table->dropColumn('fuel_level');
            });
        }

        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'rental_priority')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropColumn('rental_priority');
            });
        }

        if (Schema::hasTable('contracts') && ! Schema::hasColumn('contracts', 'accessories_json')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->json('accessories_json')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inspections') && ! Schema::hasColumn('inspections', 'fuel_level')) {
            Schema::table('inspections', function (Blueprint $table) {
                $table->unsignedTinyInteger('fuel_level')->nullable()->after('odometer');
            });
        }

        if (Schema::hasTable('vehicles') && ! Schema::hasColumn('vehicles', 'rental_priority')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->unsignedTinyInteger('rental_priority')->default(50)->after('default_deposit');
            });
        }

        if (Schema::hasTable('contracts') && Schema::hasColumn('contracts', 'accessories_json')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('accessories_json');
            });
        }
    }
};
