<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique('source');
        });

        // إزالة تكرارات التأمين قبل الفهرس الفريد (إن وُجدت)
        if (Schema::hasTable('deposits')) {
            $dupes = DB::table('deposits')
                ->select('contract_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c'))
                ->groupBy('contract_id')
                ->having('c', '>', 1)
                ->get();
            foreach ($dupes as $d) {
                DB::table('deposits')
                    ->where('contract_id', $d->contract_id)
                    ->where('id', '!=', $d->keep_id)
                    ->delete();
            }

            Schema::table('deposits', function (Blueprint $table) {
                $table->unique('contract_id');
            });
        }

        if (Schema::hasTable('inspections')) {
            Schema::table('inspections', function (Blueprint $table) {
                $table->unique(['contract_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(['source']);
        });
        if (Schema::hasTable('deposits')) {
            Schema::table('deposits', function (Blueprint $table) {
                $table->dropUnique(['contract_id']);
            });
        }
        if (Schema::hasTable('inspections')) {
            Schema::table('inspections', function (Blueprint $table) {
                $table->dropUnique(['contract_id', 'type']);
            });
        }
    }
};
