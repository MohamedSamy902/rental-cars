<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_violation_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('inquired_on');
            $table->foreignId('inquired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'inquired_on']);
            $table->index('inquired_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_violation_inquiries');
    }
};
