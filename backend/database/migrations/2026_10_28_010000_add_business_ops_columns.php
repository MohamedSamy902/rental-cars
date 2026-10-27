<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            if (! Schema::hasColumn('inspections', 'accessories_json')) {
                $table->json('accessories_json')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('inspections', 'signature_data')) {
                $table->longText('signature_data')->nullable()->after('signature_path');
            }
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'print_footer')) {
                $table->string('print_footer')->nullable()->after('logo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            if (Schema::hasColumn('inspections', 'accessories_json')) {
                $table->dropColumn('accessories_json');
            }
            if (Schema::hasColumn('inspections', 'signature_data')) {
                $table->dropColumn('signature_data');
            }
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_settings', 'print_footer')) {
                $table->dropColumn('print_footer');
            }
        });
    }
};
