<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_owners', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone', 30)->nullable();
            $table->string('phone_alt', 30)->nullable();
            $table->string('national_id', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('full_name');
            $table->index('phone');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('branch_id')->constrained('vehicle_owners')->nullOnDelete();
            $table->unsignedInteger('owner_monthly_amount')->nullable()->after('owner_id');
            $table->date('owner_since')->nullable()->after('owner_monthly_amount');
        });

        Schema::create('owner_month_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('vehicle_owners')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->char('year_month', 7); // YYYY-MM
            $table->unsignedInteger('amount');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['owner_id', 'vehicle_id', 'year_month']);
            $table->index(['owner_id', 'year_month']);
        });

        Schema::create('owner_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('vehicle_owners')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->date('paid_on');
            $table->string('payment_method', 40)->default('cash');
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['owner_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_payouts');
        Schema::dropIfExists('owner_month_dues');
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['owner_monthly_amount', 'owner_since']);
        });
        Schema::dropIfExists('vehicle_owners');
    }
};
