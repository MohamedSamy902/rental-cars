<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mobile-first auth: email optional; phone is the primary login identity.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        $dupes = DB::table('users')
            ->select('phone')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone');

        foreach ($dupes as $phone) {
            $ids = DB::table('users')->where('phone', $phone)->orderBy('id')->pluck('id');
            foreach ($ids->slice(1) as $id) {
                DB::table('users')->where('id', $id)->update([
                    'phone' => $phone.'-'.$id,
                ]);
            }
        }

        if (Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('phone');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });

        DB::table('users')->whereNull('email')->update([
            'email' => DB::raw("printf('user-%s@rpm.local', id)"),
        ]);

        // Fallback for MySQL if printf is unavailable.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::table('users')->whereNull('email')->update([
                'email' => DB::raw("CONCAT('user-', id, '@rpm.local')"),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
