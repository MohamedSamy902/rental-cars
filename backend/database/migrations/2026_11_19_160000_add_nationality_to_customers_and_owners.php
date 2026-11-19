<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit nationality for customers and vehicle owners.
 * Safe on live data: nullable column + backfill مصري when a national_id already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'nationality')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('nationality', 80)->nullable()->after('national_id');
            });
            DB::table('customers')
                ->whereNotNull('national_id')
                ->where('national_id', '!=', '')
                ->where(function ($q) {
                    $q->whereNull('nationality')->orWhere('nationality', '');
                })
                ->update(['nationality' => 'مصري']);
        }

        if (Schema::hasTable('vehicle_owners') && ! Schema::hasColumn('vehicle_owners', 'nationality')) {
            Schema::table('vehicle_owners', function (Blueprint $table) {
                $table->string('nationality', 80)->nullable()->after('national_id');
            });
            DB::table('vehicle_owners')
                ->whereNotNull('national_id')
                ->where('national_id', '!=', '')
                ->where(function ($q) {
                    $q->whereNull('nationality')->orWhere('nationality', '');
                })
                ->update(['nationality' => 'مصري']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'nationality')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('nationality');
            });
        }
        if (Schema::hasTable('vehicle_owners') && Schema::hasColumn('vehicle_owners', 'nationality')) {
            Schema::table('vehicle_owners', function (Blueprint $table) {
                $table->dropColumn('nationality');
            });
        }
    }
};
