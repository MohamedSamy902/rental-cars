<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('last_sent_at');
            $table->timestamps();

            $table->unique(['rule_key', 'subject_type', 'subject_id'], 'notification_reminders_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reminders');
    }
};
