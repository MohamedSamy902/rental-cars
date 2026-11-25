<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BE-8: جدول ملخص شهري مشتق — يُملأ بأمر إعادة بناء فقط.
 * القراءة التشغيلية تبقى من ProfitabilityService حتى يثبت برهان التكافؤ + قرار التفعيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_profit_month_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedBigInteger('vehicle_id');
            $table->string('label')->nullable();
            $table->unsignedInteger('revenue')->default(0);
            $table->unsignedInteger('maintenance')->default(0);
            $table->unsignedInteger('expenses')->default(0);
            $table->integer('operating_profit')->default(0);
            $table->timestamp('built_at');
            $table->timestamps();

            $table->unique(['year', 'month', 'vehicle_id'], 'vehicle_profit_month_uq');
            $table->index(['year', 'month'], 'vehicle_profit_month_ym_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_profit_month_summaries');
    }
};
