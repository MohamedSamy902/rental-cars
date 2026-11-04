<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicle_documents') && ! Schema::hasColumn('vehicle_documents', 'document_number')) {
            Schema::table('vehicle_documents', function (Blueprint $table) {
                $table->string('document_number', 80)->nullable()->after('type');
            });
        }

        if (Schema::hasTable('vehicle_document_histories') && ! Schema::hasColumn('vehicle_document_histories', 'document_number')) {
            Schema::table('vehicle_document_histories', function (Blueprint $table) {
                $table->string('document_number', 80)->nullable()->after('type');
            });
        }

        if (Schema::hasTable('vehicle_owners')) {
            $drop = [];
            if (Schema::hasColumn('vehicle_owners', 'license_front_path')) {
                $drop[] = 'license_front_path';
            }
            if (Schema::hasColumn('vehicle_owners', 'license_back_path')) {
                $drop[] = 'license_back_path';
            }
            if ($drop !== []) {
                Schema::table('vehicle_owners', function (Blueprint $table) use ($drop) {
                    $table->dropColumn($drop);
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicle_owners')) {
            Schema::table('vehicle_owners', function (Blueprint $table) {
                if (! Schema::hasColumn('vehicle_owners', 'license_front_path')) {
                    $table->string('license_front_path')->nullable();
                }
                if (! Schema::hasColumn('vehicle_owners', 'license_back_path')) {
                    $table->string('license_back_path')->nullable();
                }
            });
        }

        if (Schema::hasTable('vehicle_document_histories') && Schema::hasColumn('vehicle_document_histories', 'document_number')) {
            Schema::table('vehicle_document_histories', function (Blueprint $table) {
                $table->dropColumn('document_number');
            });
        }

        if (Schema::hasTable('vehicle_documents') && Schema::hasColumn('vehicle_documents', 'document_number')) {
            Schema::table('vehicle_documents', function (Blueprint $table) {
                $table->dropColumn('document_number');
            });
        }
    }
};
