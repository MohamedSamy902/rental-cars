<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_number_sequences', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });

        $max = 0;
        if (Schema::hasTable('contracts')) {
            $last = DB::table('contracts')->orderByDesc('id')->value('number');
            if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
                $max = (int) $m[1];
            }
        }

        DB::table('contract_number_sequences')->insert([
            'id' => 1,
            'next_value' => $max + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_number_sequences');
    }
};
