<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_templates')) {
            return;
        }

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40); // contract | handover_receipt
            $table->string('title');
            $table->longText('content');
            $table->string('format', 20)->default('html'); // html | markdown | docx
            $table->string('storage_path')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->unique(['type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
