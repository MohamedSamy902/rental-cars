<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (Schema::hasColumn('vehicles', 'target_monthly_income')) {
                $table->dropColumn('target_monthly_income');
            }
            if (Schema::hasColumn('vehicles', 'owner_agreement_number')) {
                $table->dropColumn('owner_agreement_number');
            }
            if (Schema::hasColumn('vehicles', 'owner_agreement_notes')) {
                $table->dropColumn('owner_agreement_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'target_monthly_income')) {
                $table->unsignedInteger('target_monthly_income')->nullable()->after('default_deposit');
            }
            if (! Schema::hasColumn('vehicles', 'owner_agreement_number')) {
                $table->string('owner_agreement_number', 100)->nullable()->after('owner_agreement_ends_on');
            }
            if (! Schema::hasColumn('vehicles', 'owner_agreement_notes')) {
                $table->text('owner_agreement_notes')->nullable()->after('owner_agreement_number');
            }
        });
    }
};
