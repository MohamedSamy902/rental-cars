<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicle_documents', 'file_path_back')) {
                $table->string('file_path_back')->nullable()->after('file_path');
            }
        });

        Schema::table('vehicle_document_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicle_document_histories', 'file_path_back')) {
                $table->string('file_path_back')->nullable()->after('file_path');
            }
        });

        Schema::table('vehicle_owners', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicle_owners', 'id_front_path')) {
                $table->string('id_front_path')->nullable()->after('national_id');
            }
            if (! Schema::hasColumn('vehicle_owners', 'id_back_path')) {
                $table->string('id_back_path')->nullable()->after('id_front_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_documents', function (Blueprint $table) {
            if (Schema::hasColumn('vehicle_documents', 'file_path_back')) {
                $table->dropColumn('file_path_back');
            }
        });
        Schema::table('vehicle_document_histories', function (Blueprint $table) {
            if (Schema::hasColumn('vehicle_document_histories', 'file_path_back')) {
                $table->dropColumn('file_path_back');
            }
        });
        Schema::table('vehicle_owners', function (Blueprint $table) {
            foreach (['id_front_path', 'id_back_path'] as $col) {
                if (Schema::hasColumn('vehicle_owners', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
