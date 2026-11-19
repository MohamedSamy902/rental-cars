<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agreed cash deposit may be held in currencies other than EGP.
 * Live-safe: nullable column + backfill Egyptian pound for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contracts') || Schema::hasColumn('contracts', 'frozen_deposit_currency')) {
            return;
        }

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('frozen_deposit_currency', 40)->nullable()->after('frozen_agreed_deposit');
        });

        DB::table('contracts')
            ->where(function ($q) {
                $q->whereNull('frozen_deposit_currency')->orWhere('frozen_deposit_currency', '');
            })
            ->update(['frozen_deposit_currency' => 'جنيه مصري']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('contracts') || ! Schema::hasColumn('contracts', 'frozen_deposit_currency')) {
            return;
        }

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('frozen_deposit_currency');
        });
    }
};
