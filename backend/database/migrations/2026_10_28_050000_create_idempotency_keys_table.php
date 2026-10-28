<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128);
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('method', 16);
            $table->string('path', 255);
            $table->string('request_hash', 64);
            $table->string('status', 20)->default('processing');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
