<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مصدر وتاريخ صدور البطاقة/الرخصة لطباعة عقود الإيجار.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'national_id_issued_from')) {
                $table->string('national_id_issued_from', 120)->nullable()->after('national_id');
            }
            if (! Schema::hasColumn('customers', 'national_id_issued_on')) {
                $table->date('national_id_issued_on')->nullable()->after('national_id_issued_from');
            }
            if (! Schema::hasColumn('customers', 'license_issued_from')) {
                $table->string('license_issued_from', 120)->nullable()->after('license_number');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            foreach (['national_id_issued_from', 'national_id_issued_on', 'license_issued_from'] as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
