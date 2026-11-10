<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_custody_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('vehicle_owners')->restrictOnDelete();
            $table->string('number', 64);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('monthly_amount')->default(0);
            $table->longText('rendered_html');
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->unsignedInteger('template_version')->default(1);
            $table->json('variables_json')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique('number');
            $table->index(['vehicle_id', 'issued_at']);
            $table->index(['owner_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_custody_agreements');
    }
};
