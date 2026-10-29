<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('violation_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('channel'); // assisted | csv_import | official_api_stub
            $table->string('status')->default('completed'); // completed | partial | failed
            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('rows_created')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->json('meta')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('violations', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('notes');
            $table->string('external_number')->nullable()->after('source');
            $table->boolean('needs_review')->default(false)->after('external_number');
            $table->foreignId('sync_run_id')->nullable()->after('needs_review')
                ->constrained('violation_sync_runs')->nullOnDelete();
            $table->unique(['source', 'external_number']);
        });
    }

    public function down(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_number']);
            $table->dropConstrainedForeignId('sync_run_id');
            $table->dropColumn(['source', 'external_number', 'needs_review']);
        });
        Schema::dropIfExists('violation_sync_runs');
    }
};
