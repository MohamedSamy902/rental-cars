<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التقييمات موقوفة بالكامل — إسقاط الجدول من قاعدة البيانات.
 * الإنشاء الأصلي كان في 2026_10_28_020000_add_gap_closure_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('evaluations');
    }

    public function down(): void
    {
        if (Schema::hasTable('evaluations')) {
            return;
        }

        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('period', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
