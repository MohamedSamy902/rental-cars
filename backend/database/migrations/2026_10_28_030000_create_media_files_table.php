<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->string('kind', 20); // image|video
            $table->string('collection', 40)->default('general');
            $table->string('purpose', 60)->nullable(); // national_id|license|document|damage|other
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->nullableMorphs('attachable');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['collection', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
