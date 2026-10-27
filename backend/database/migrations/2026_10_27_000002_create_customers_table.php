<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone');
            $table->string('phone_alt')->nullable();
            $table->string('email')->nullable();
            $table->string('national_id')->nullable();
            $table->date('national_id_expires_on')->nullable();
            $table->string('passport_number')->nullable();
            $table->date('passport_expires_on')->nullable();
            $table->date('birth_date');
            $table->string('address')->nullable();
            $table->string('license_number');
            $table->date('license_expires_on');
            $table->string('status')->default('active');
            $table->string('blacklist_reason')->nullable();
            $table->foreignId('blacklisted_by')->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('phone');
            $table->index('status');
            $table->index('national_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
